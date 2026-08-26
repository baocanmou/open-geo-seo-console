<?php

declare(strict_types=1);

namespace OpenGeo;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class ProviderEndpointPolicy
{
    public static function allows(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || str_ends_with($host, '.') || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return false;
        }
        $path = (string) ($parts['path'] ?? '/');
        if ($host === 'index.commoncrawl.org') {
            return $path === '/collinfo.json' || (bool) preg_match('/\A\/CC-MAIN-\d{4}-\d{2}-index\z/', $path);
        }
        if ($host === 'www.googleapis.com') {
            return $path === '/pagespeedonline/v5/runPagespeed';
        }
        return false;
    }
}

final class ProviderStatusPolicy
{
    public static function accepts(int $status, bool $allowNotFound = false): bool
    {
        return ($status >= 200 && $status < 300) || ($allowNotFound && $status === 404);
    }
}

final class LighthouseTargetPolicy
{
    public static function allows(string $url, array $allowedHosts): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || str_ends_with($host, '.') || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            return false;
        }
        $allowed = array_values(array_unique(array_map(
            static fn(string $candidate): string => strtolower(rtrim($candidate, '.')),
            $allowedHosts
        )));
        return in_array($host, $allowed, true);
    }
}

final class PublicDataParser
{
    public static function latestCommonCrawlIndex(string $json): array
    {
        $rows = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($rows)) {
            throw new RuntimeException('Common Crawl collection list is malformed.');
        }
        $indexes = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if (!preg_match('/\ACC-MAIN-\d{4}-\d{2}\z/', $id)) {
                continue;
            }
            $apiUrl = 'https://index.commoncrawl.org/' . $id . '-index';
            if (ProviderEndpointPolicy::allows($apiUrl)) {
                $indexes[$id] = $apiUrl;
            }
        }
        if ($indexes === []) {
            throw new RuntimeException('Common Crawl did not return a usable index.');
        }
        krsort($indexes, SORT_STRING);
        $id = array_key_first($indexes);
        return ['id' => $id, 'api_url' => $indexes[$id]];
    }

    public static function commonCrawlSummary(string $ndjson, array $allowedHosts): array
    {
        $allowed = array_fill_keys(array_map(static fn(string $host): string => strtolower(rtrim($host, '.')), $allowedHosts), true);
        $urls = [];
        $latest = null;
        $invalidRows = 0;
        $lines = preg_split('/\R/', trim($ndjson)) ?: [];
        foreach (array_slice($lines, 0, 2500) as $line) {
            if ($line === '') {
                continue;
            }
            try {
                $row = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $invalidRows++;
                continue;
            }
            if (!is_array($row) || (string) ($row['status'] ?? '') !== '200') {
                continue;
            }
            $mime = strtolower((string) ($row['mime'] ?? $row['mime-detected'] ?? ''));
            if (!str_starts_with($mime, 'text/html')) {
                continue;
            }
            $url = (string) ($row['url'] ?? '');
            $parts = parse_url($url);
            $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
            if (!isset($allowed[$host]) || !in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
                continue;
            }
            $normalized = preg_replace('/#.*\z/', '', $url) ?: $url;
            $urls[$normalized] = true;
            $timestamp = (string) ($row['timestamp'] ?? '');
            if (preg_match('/\A\d{14}\z/', $timestamp) && ($latest === null || strcmp($timestamp, $latest) > 0)) {
                $latest = $timestamp;
            }
        }
        $latestCapture = null;
        if ($latest !== null) {
            $parsed = DateTimeImmutable::createFromFormat('!YmdHis', $latest, new DateTimeZone('UTC'));
            if ($parsed instanceof DateTimeImmutable) {
                $latestCapture = $parsed->format('Y-m-d H:i:s');
            }
        }
        return [
            'indexed_pages' => count($urls),
            'sample_limit' => 500,
            'sample_limited' => count($urls) >= 500 || count($lines) > 500,
            'latest_capture_at' => $latestCapture,
            'timestamp_timezone' => 'UTC',
            'invalid_rows' => $invalidRows,
        ];
    }

    public static function pageSpeedSummary(string $json): array
    {
        $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $lighthouse = is_array($payload['lighthouseResult'] ?? null) ? $payload['lighthouseResult'] : [];
        if ($lighthouse === []) {
            $message = (string) ($payload['error']['message'] ?? 'PageSpeed response does not contain Lighthouse data.');
            throw new RuntimeException(mb_substr($message, 0, 220));
        }
        $categories = is_array($lighthouse['categories'] ?? null) ? $lighthouse['categories'] : [];
        $audits = is_array($lighthouse['audits'] ?? null) ? $lighthouse['audits'] : [];
        $score = static function (array $categories, string $key): ?float {
            $raw = $categories[$key]['score'] ?? null;
            if (!is_numeric($raw)) {
                return null;
            }
            return round(max(0.0, min(1.0, (float) $raw)) * 100, 1);
        };
        $metric = static function (array $audits, string $key, bool $integer = true): int|float|null {
            $raw = $audits[$key]['numericValue'] ?? null;
            if (!is_numeric($raw)) {
                return null;
            }
            $value = max(0.0, (float) $raw);
            return $integer ? (int) round($value) : round($value, 4);
        };
        return [
            'strategy' => 'mobile',
            'performance_score' => $score($categories, 'performance'),
            'seo_score' => $score($categories, 'seo'),
            'accessibility_score' => $score($categories, 'accessibility'),
            'best_practices_score' => $score($categories, 'best-practices'),
            'lcp_ms' => $metric($audits, 'largest-contentful-paint'),
            'cls' => $metric($audits, 'cumulative-layout-shift', false),
            'inp_ms' => $metric($audits, 'interaction-to-next-paint'),
            'tbt_ms' => $metric($audits, 'total-blocking-time'),
            'fetch_time' => mb_substr((string) ($lighthouse['fetchTime'] ?? ''), 0, 40) ?: null,
        ];
    }

    public static function lighthouseSummary(string $json, string $requestedUrl): array
    {
        $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !is_array($payload['categories'] ?? null)) {
            throw new RuntimeException('Lighthouse report does not contain category data.');
        }
        $wrapped = json_encode(['lighthouseResult' => $payload], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($wrapped)) {
            throw new RuntimeException('Cannot normalize Lighthouse report.');
        }
        $summary = self::pageSpeedSummary($wrapped);
        $summary['strategy'] = 'mobile-emulated';
        $summary['runner'] = 'self-hosted-lighthouse';
        $summary['requested_url'] = mb_substr($requestedUrl, 0, 1000);
        $summary['final_url'] = mb_substr((string) ($payload['finalUrl'] ?? ''), 0, 1000) ?: null;
        $summary['lighthouse_version'] = mb_substr((string) ($payload['lighthouseVersion'] ?? ''), 0, 40) ?: null;
        return $summary;
    }
}

final class LighthouseRunner
{
    private const RUN_AS_USER = 'open-geo-lighthouse';
    private const RUNUSER_BIN = '/usr/sbin/runuser';
    private const TIMEOUT_BIN = '/usr/bin/timeout';
    private const ENV_BIN = '/usr/bin/env';
    private const PKILL_BIN = '/usr/bin/pkill';
    private const LIGHTHOUSE_BIN = '/usr/local/bin/lighthouse';
    private const CHROMIUM_BIN = '/usr/bin/chromium-browser';
    private const OUTPUT_DIR = '/var/lib/open-geo-lighthouse';
    private const LOCK_FILE = '/var/lock/open-geo-lighthouse.lock';
    private const EGRESS_MARKER = '/etc/open-geo/lighthouse-egress-ready';
    private const EGRESS_VERIFY_BIN = '/usr/local/sbin/open-geo-lighthouse-egress';

    public function run(string $url, array $allowedHosts): array
    {
        if (!LighthouseTargetPolicy::allows($url, $allowedHosts)) {
            throw new RuntimeException('Lighthouse target is not an approved registered HTTPS URL.');
        }
        if (!function_exists('proc_open') || !function_exists('flock')) {
            throw new RuntimeException('Required process controls are unavailable.');
        }
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            throw new RuntimeException('Lighthouse worker must run from the privileged CLI scheduler.');
        }
        foreach ([self::RUNUSER_BIN, self::TIMEOUT_BIN, self::ENV_BIN, self::PKILL_BIN, self::LIGHTHOUSE_BIN, self::CHROMIUM_BIN, self::EGRESS_VERIFY_BIN] as $binary) {
            if (!is_file($binary) || !is_executable($binary)) {
                throw new RuntimeException('A required Lighthouse binary is unavailable.');
            }
        }
        if (!is_file(self::EGRESS_MARKER) || !is_dir(self::OUTPUT_DIR)) {
            throw new RuntimeException('Lighthouse isolation is not provisioned.');
        }
        $this->assertEgressIsolation();

        $lock = fopen(self::LOCK_FILE, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Another Lighthouse audit is already running.');
        }
        $reportPath = self::OUTPUT_DIR . '/report-' . Security::uuid() . '.json';
        $this->terminateStaleBrowserProcesses();
        try {
            $command = [
                self::RUNUSER_BIN, '-u', self::RUN_AS_USER, '--', self::ENV_BIN,
                'HOME=' . self::OUTPUT_DIR,
                'XDG_CONFIG_HOME=' . self::OUTPUT_DIR . '/.config',
                self::TIMEOUT_BIN, '--signal=TERM', '--kill-after=10s', '180s',
                self::LIGHTHOUSE_BIN, $url,
                '--output=json', '--output-path=' . $reportPath,
                '--only-categories=performance,seo,accessibility,best-practices',
                '--chrome-path=' . self::CHROMIUM_BIN,
                '--port=49222',
                '--chrome-flags=--headless=new --disable-dev-shm-usage --disable-background-networking --disable-component-update --disable-sync --metrics-recording-only --no-first-run',
                '--quiet',
            ];
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open($command, $descriptors, $pipes, null, []);
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start isolated Lighthouse process.');
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            if ($exitCode !== 0) {
                $detail = trim((string) ($stderr !== '' ? $stderr : $stdout));
                throw new RuntimeException('Lighthouse process failed: ' . mb_substr($detail, 0, 220));
            }
            $size = is_file($reportPath) ? filesize($reportPath) : false;
            if (!is_int($size) || $size < 100 || $size > 12582912) {
                throw new RuntimeException('Lighthouse report is missing or outside the allowed size.');
            }
            $json = file_get_contents($reportPath);
            if (!is_string($json)) {
                throw new RuntimeException('Cannot read Lighthouse report.');
            }
            $summary = PublicDataParser::lighthouseSummary($json, $url);
            $finalUrl = (string) ($summary['final_url'] ?? '');
            if ($finalUrl !== '' && !LighthouseTargetPolicy::allows($finalUrl, $allowedHosts)) {
                throw new RuntimeException('Lighthouse ended outside the registered site.');
            }
            return $summary;
        } finally {
            $this->terminateStaleBrowserProcesses();
            if (is_file($reportPath)) {
                unlink($reportPath);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function terminateStaleBrowserProcesses(): void
    {
        foreach (['-TERM', '-KILL'] as $signal) {
            $process = proc_open([
                self::PKILL_BIN, $signal, '-u', self::RUN_AS_USER, '-f',
                '/usr/bin/chromium-browser|/usr/lib64/chromium-browser',
            ], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ], $pipes, null, []);
            if (is_resource($process)) {
                proc_close($process);
            }
            if ($signal === '-TERM') {
                usleep(300000);
            }
        }
    }

    private function assertEgressIsolation(): void
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([self::EGRESS_VERIFY_BIN, 'verify'], $descriptors, $pipes, null, []);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot verify Lighthouse egress isolation.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $detail = trim((string) ($stderr !== '' ? $stderr : $stdout));
            throw new RuntimeException('Lighthouse egress isolation is inactive: ' . mb_substr($detail, 0, 160));
        }
    }
}

final class PublicDataCollector
{
    private const USER_AGENT = 'OpenGEO-PublicData/1.1';

    private readonly LighthouseRunner $lighthouse;

    public function __construct(private readonly PDO $db, private readonly SafeHttpClient $http, ?LighthouseRunner $lighthouse = null)
    {
        $this->lighthouse = $lighthouse ?? new LighthouseRunner();
    }

    public function collectSite(int $siteId): array
    {
        $stmt = $this->db->prepare("SELECT id, domain, canonical_url FROM sites WHERE id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$siteId]);
        $site = $stmt->fetch();
        if (!$site) {
            throw new RuntimeException('Active site not found.');
        }
        $aliasStmt = $this->db->prepare('SELECT hostname FROM site_aliases WHERE site_id = ?');
        $aliasStmt->execute([$siteId]);
        $allowedHosts = array_values(array_unique(array_merge([(string) $site['domain']], array_column($aliasStmt->fetchAll(), 'hostname'))));

        $this->http->beginAudit();
        $results = [];
        $results['common_crawl'] = $this->collectProvider($site, 'common_crawl', function () use ($site, $allowedHosts): array {
            $collectionsUrl = 'https://index.commoncrawl.org/collinfo.json';
            $collections = $this->fetch($collectionsUrl, 1048576);
            $index = PublicDataParser::latestCommonCrawlIndex($collections->body);
            $query = $index['api_url']
                . '?url=' . rawurlencode((string) $site['domain'] . '/*')
                . '&output=json&filter=status%3A200&filter=mime%3Atext%2Fhtml&collapse=urlkey&limit=500';
            $records = $this->fetch($query, 4194304, true);
            $summary = PublicDataParser::commonCrawlSummary($records->body, $allowedHosts);
            $summary['crawl_index'] = $index['id'];
            return [$summary, $query, $summary['indexed_pages'] > 0 ? 'success' : 'empty'];
        });

        $results['lighthouse_local'] = $this->collectProvider($site, 'lighthouse_local', function () use ($site, $allowedHosts): array {
            $preflight = $this->http->get((string) $site['canonical_url'], $allowedHosts, self::USER_AGENT, 524288);
            if ($preflight->status < 200 || $preflight->status >= 400) {
                throw new RuntimeException('Lighthouse target preflight returned HTTP ' . $preflight->status . '.');
            }
            $summary = $this->lighthouse->run((string) $site['canonical_url'], $allowedHosts);
            return [$summary, 'urn:lighthouse:self-hosted:v12.8.2', 'success'];
        });

        return $results;
    }

    private function collectProvider(array $site, string $integrationKey, callable $callback): array
    {
        try {
            [$summary, $sourceReference, $status] = $callback();
            $this->storeObservation((int) $site['id'], $integrationKey, $status, $summary, $sourceReference);
            $this->db->prepare("UPDATE integrations SET status = 'public', last_sync_at = NOW(), updated_at = NOW() WHERE integration_key = ?")
                ->execute([$integrationKey]);
            return ['status' => $status, 'summary' => $summary];
        } catch (Throwable $error) {
            $summary = ['error' => mb_substr($error->getMessage(), 0, 220)];
            $this->storeObservation((int) $site['id'], $integrationKey, 'error', $summary, null);
            return ['status' => 'error', 'summary' => $summary];
        }
    }

    private function fetch(string $url, int $maxBytes, bool $allowNotFound = false): FetchResult
    {
        if (!ProviderEndpointPolicy::allows($url)) {
            throw new RuntimeException('Public provider endpoint is not allowlisted.');
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $result = $this->http->get($url, [$host], self::USER_AGENT, $maxBytes);
        if (!ProviderStatusPolicy::accepts($result->status, $allowNotFound)) {
            throw new RuntimeException('Public provider returned HTTP ' . $result->status . '.');
        }
        return $result;
    }

    private function storeObservation(int $siteId, string $integrationKey, string $status, array $summary, ?string $sourceReference): void
    {
        $json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            throw new RuntimeException('Cannot encode provider observation.');
        }
        $stmt = $this->db->prepare("INSERT INTO provider_observations
          (public_id, site_id, integration_key, snapshot_date, status, summary_json, source_reference, evidence_hash, observed_at, created_at)
          VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, NOW(), NOW())
          ON DUPLICATE KEY UPDATE status = VALUES(status), summary_json = VALUES(summary_json), source_reference = VALUES(source_reference),
            evidence_hash = VALUES(evidence_hash), observed_at = NOW()");
        $stmt->execute([
            Security::uuid(),
            $siteId,
            $integrationKey,
            $status,
            $json,
            $sourceReference !== null ? mb_substr($sourceReference, 0, 1000) : null,
            hash('sha256', $integrationKey . "\n" . $json),
        ]);
    }
}
