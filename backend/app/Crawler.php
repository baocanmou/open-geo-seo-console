<?php

declare(strict_types=1);

namespace OpenGeo;

use DOMDocument;
use DOMXPath;
use RuntimeException;

final class AuditBudgetExceeded extends RuntimeException {}

final class FetchResult
{
    public function __construct(
        public readonly string $requestedUrl,
        public readonly string $finalUrl,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly int $durationMs,
        public readonly int $headerBytes = 0,
    ) {}

    public function contentType(): string
    {
        return trim(explode(';', (string) ($this->headers['content-type'] ?? ''))[0]);
    }
}

final class SafeHttpClient
{
    private const DEFAULT_UA = 'OPENGEO-Audit/1.0 (+https://github.com/yht0912/open-geo-seo-console)';
    private float $auditStartedAt = 0.0;
    private int $auditRequests = 0;
    private int $auditBytes = 0;

    public function beginAudit(): void
    {
        $this->auditStartedAt = microtime(true);
        $this->auditRequests = 0;
        $this->auditBytes = 0;
    }

    public function get(string $url, array $allowedHosts, ?string $userAgent = null, int $maxBytes = 2097152): FetchResult
    {
        $allowed = array_values(array_unique(array_map(static fn(string $host): string => strtolower(rtrim($host, '.')), $allowedHosts)));
        $current = $url;
        $totalDurationMs = 0;
        for ($redirect = 0; $redirect <= 3; $redirect++) {
            $this->reserveAuditRequest();
            [$host, $port, $ip] = $this->validateAndResolve($current, $allowed);
            $result = $this->singleRequest($current, $host, $port, $ip, $userAgent ?? self::DEFAULT_UA, $maxBytes);
            $totalDurationMs += $result->durationMs;
            if (!in_array($result->status, [301, 302, 303, 307, 308], true)) {
                return new FetchResult($url, $result->finalUrl, $result->status, $result->headers, $result->body, $totalDurationMs, $result->headerBytes);
            }
            $location = $result->headers['location'] ?? '';
            if ($location === '') {
                return new FetchResult($url, $result->finalUrl, $result->status, $result->headers, $result->body, $totalDurationMs, $result->headerBytes);
            }
            $current = $this->resolveUrl($current, $location);
        }
        throw new RuntimeException('Redirect limit exceeded.');
    }

    private function validateAndResolve(string $url, array $allowedHosts): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Invalid crawl URL.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $rawHost = strtolower((string) $parts['host']);
        if ($rawHost === '' || str_ends_with($rawHost, '.')) {
            throw new RuntimeException('Crawl host must use canonical form without a trailing dot.');
        }
        $host = $rawHost;
        if (!in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Unsupported crawl URL.');
        }
        if (!in_array($host, $allowedHosts, true)) {
            throw new RuntimeException('Redirected outside the registered site.');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
            throw new RuntimeException('Non-standard crawl ports are not allowed.');
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = [];
        foreach ($records as $record) {
            $candidate = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($candidate !== null) {
                $ips[] = $candidate;
            }
        }
        if ($ips === []) {
            throw new RuntimeException('Registered hostname did not resolve.');
        }
        foreach ($ips as $candidate) {
            if (!self::isGloballyRoutableIp($candidate)) {
                throw new RuntimeException('Registered hostname resolved to a non-global address.');
            }
        }
        usort($ips, static fn(string $a, string $b): int => str_contains($a, ':') <=> str_contains($b, ':'));
        return [$host, $port, $ips[0]];
    }

    public static function isGloballyRoutableIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16 && !self::cidrContains($packed, '2000::/3')) {
            return false;
        }
        $blocked = strlen($packed) === 4
            ? [
                '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
                '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
                '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
                '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
            ]
            : [
                '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20',
            ];
        foreach ($blocked as $cidr) {
            if (self::cidrContains($packed, $cidr)) {
                return false;
            }
        }
        return true;
    }

    private static function cidrContains(string $packedIp, string $cidr): bool
    {
        [$network, $prefixText] = explode('/', $cidr, 2);
        $packedNetwork = inet_pton($network);
        if ($packedNetwork === false || strlen($packedNetwork) !== strlen($packedIp)) {
            return false;
        }
        $prefix = (int) $prefixText;
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if ($wholeBytes > 0 && substr($packedIp, 0, $wholeBytes) !== substr($packedNetwork, 0, $wholeBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedNetwork[$wholeBytes]) & $mask);
    }

    private function reserveAuditRequest(): void
    {
        if ($this->auditStartedAt <= 0) {
            return;
        }
        $this->enforceAuditBudget();
        $this->auditRequests++;
        if ($this->auditRequests > max(10, min(Config::int('AUDIT_MAX_REQUESTS', 64), 100))) {
            throw new AuditBudgetExceeded('Audit request budget exceeded.');
        }
    }

    public function assertAuditBudget(): void
    {
        $this->enforceAuditBudget();
    }

    private function enforceAuditBudget(): void
    {
        if ($this->auditStartedAt <= 0) {
            return;
        }
        $maxSeconds = max(15, min(Config::int('AUDIT_MAX_SECONDS', 50), 300));
        $maxBytes = max(1048576, min(Config::int('AUDIT_MAX_TOTAL_BYTES', 16777216), 33554432));
        if (microtime(true) - $this->auditStartedAt > $maxSeconds) {
            throw new AuditBudgetExceeded('Audit time budget exceeded.');
        }
        if ($this->auditBytes > $maxBytes) {
            throw new AuditBudgetExceeded('Audit byte budget exceeded.');
        }
    }

    private function singleRequest(string $url, string $host, int $port, string $ip, string $userAgent, int $maxBytes): FetchResult
    {
        $curl = curl_init();
        if ($curl === false) {
            throw new RuntimeException('Cannot initialize HTTP client.');
        }
        $headers = [];
        $body = '';
        $tooLarge = false;
        $headerBytes = 0;
        $headersTooLarge = false;
        $resolveIp = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => max(1, min(Config::int('CRAWL_CONNECT_TIMEOUT', 8), 15)),
            CURLOPT_TIMEOUT => max(3, min(Config::int('CRAWL_TIMEOUT', 18), 30)),
            CURLOPT_USERAGENT => substr($userAgent, 0, 255),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8,*/*;q=0.5', 'Accept-Encoding: identity'],
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$resolveIp}"],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers, &$headerBytes, &$headersTooLarge): int {
                $length = strlen($line);
                $headerBytes += $length;
                if ($headerBytes > 65536) {
                    $headersTooLarge = true;
                    return 0;
                }
                $position = strpos($line, ':');
                if ($position !== false) {
                    $key = strtolower(trim(substr($line, 0, $position)));
                    $headers[$key] = trim(substr($line, $position + 1));
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $started = microtime(true);
        $ok = curl_exec($curl);
        $duration = (int) round((microtime(true) - $started) * 1000);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $this->auditBytes += strlen($body) + $headerBytes;
        $this->enforceAuditBudget();
        if ($tooLarge) {
            throw new RuntimeException('Remote response exceeded the crawl size limit.');
        }
        if ($headersTooLarge) {
            throw new RuntimeException('Remote response headers exceeded the crawl size limit.');
        }
        if ($ok === false && $status === 0) {
            throw new RuntimeException('Remote request failed: ' . substr($error, 0, 180));
        }
        return new FetchResult($url, $url, $status, $headers, $body, $duration, $headerBytes);
    }

    private function resolveUrl(string $base, string $location): string
    {
        if (preg_match('~^https?://~i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Cannot resolve redirect URL.');
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = (string) ($parts['path'] ?? '/');
        return $origin . preg_replace('~/[^/]*$~', '/', $path) . $location;
    }
}

final class RobotsPolicy
{
    private const MAX_LINES = 512;
    private const MAX_GROUPS = 64;
    private const MAX_AGENTS = 128;
    private const MAX_RULES = 64;
    private const MAX_RULE_LENGTH = 256;
    private array $groups = [];
    private array $sitemaps = [];

    public static function parse(string $content): self
    {
        $policy = new self();
        $agents = [];
        $rules = [];
        $lineCount = 0;
        $totalAgents = 0;
        $totalRules = 0;
        $flush = static function () use (&$policy, &$agents, &$rules): void {
            if ($agents !== [] && count($policy->groups) < self::MAX_GROUPS) {
                $policy->groups[] = ['agents' => $agents, 'rules' => $rules];
            }
            $agents = [];
            $rules = [];
        };
        foreach (preg_split('/\R/', $content) ?: [] as $rawLine) {
            $lineCount++;
            if ($lineCount > self::MAX_LINES) {
                break;
            }
            $line = trim(explode('#', $rawLine, 2)[0]);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                if ($rules !== []) {
                    $flush();
                }
                if ($totalAgents < self::MAX_AGENTS && strlen($value) <= 128) {
                    $agents[] = strtolower($value);
                    $totalAgents++;
                }
            } elseif (in_array($field, ['allow', 'disallow'], true) && $agents !== [] && $totalRules < self::MAX_RULES && strlen($value) <= self::MAX_RULE_LENGTH) {
                $rules[] = ['type' => $field, 'path' => $value];
                $totalRules++;
            } elseif ($field === 'sitemap' && count($policy->sitemaps) < 32 && strlen($value) <= 1000 && filter_var($value, FILTER_VALIDATE_URL)) {
                $policy->sitemaps[] = $value;
            }
        }
        $flush();
        return $policy;
    }

    public function allows(string $userAgent, string $path = '/', ?callable $budgetCheck = null): bool
    {
        $agent = strtolower($userAgent);
        $path = mb_substr($path, 0, 2048);
        $matched = [];
        $bestSpecificity = -1;
        foreach ($this->groups as $group) {
            if ($budgetCheck !== null) {
                $budgetCheck();
            }
            foreach ($group['agents'] as $candidate) {
                $specificity = $candidate === '*' ? 0 : (str_contains($agent, $candidate) ? strlen($candidate) : -1);
                if ($specificity > $bestSpecificity) {
                    $bestSpecificity = $specificity;
                    $matched = $group['rules'];
                } elseif ($specificity === $bestSpecificity && $specificity >= 0) {
                    foreach ($group['rules'] as $rule) {
                        if (count($matched) >= self::MAX_RULES) {
                            break;
                        }
                        $matched[] = $rule;
                    }
                }
            }
        }
        $decision = true;
        $longest = -1;
        foreach ($matched as $rule) {
            if ($budgetCheck !== null) {
                $budgetCheck();
            }
            $rulePath = (string) $rule['path'];
            if ($rulePath === '') {
                continue;
            }
            if (self::matchesRule($rulePath, $path, $budgetCheck) && strlen($rulePath) >= $longest) {
                if (strlen($rulePath) > $longest || $rule['type'] === 'allow') {
                    $decision = $rule['type'] === 'allow';
                    $longest = strlen($rulePath);
                }
            }
        }
        return $decision;
    }

    private static function matchesRule(string $pattern, string $path, ?callable $budgetCheck = null): bool
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        $patternLength = strlen($pattern);
        $pathLength = strlen($path);
        $patternIndex = 0;
        $pathIndex = 0;
        $starIndex = -1;
        $starPathIndex = 0;
        $iterations = 0;
        while ($pathIndex < $pathLength) {
            $iterations++;
            if ($budgetCheck !== null && $iterations % 128 === 0) {
                $budgetCheck();
            }
            if ($patternIndex < $patternLength && $pattern[$patternIndex] !== '*' && $pattern[$patternIndex] === $path[$pathIndex]) {
                $patternIndex++;
                $pathIndex++;
            } elseif ($patternIndex < $patternLength && $pattern[$patternIndex] === '*') {
                $starIndex = $patternIndex++;
                $starPathIndex = $pathIndex;
            } elseif ($starIndex >= 0) {
                $patternIndex = $starIndex + 1;
                $pathIndex = ++$starPathIndex;
            } else {
                return false;
            }
            if (!$anchored && $patternIndex >= $patternLength) {
                return true;
            }
        }
        while ($patternIndex < $patternLength && $pattern[$patternIndex] === '*') {
            $patternIndex++;
        }
        return $patternIndex === $patternLength && (!$anchored || $pathIndex === $pathLength);
    }

    public function sitemaps(): array
    {
        return array_values(array_unique($this->sitemaps));
    }
}

final class SitemapReader
{
    public static function urls(string $xml, array $allowedHosts, int $limit): array
    {
        if ($xml === '' || strlen($xml) > 1048576 || substr_count(strtolower($xml), '<url') > 10000) {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return [];
        }
        $xpath = new DOMXPath($dom);
        $rows = [];
        foreach ($xpath->query('//*[local-name()="url"]/*[local-name()="loc"]') ?: [] as $node) {
            $url = trim($node->textContent);
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== '' && in_array($host, $allowedHosts, true) && !isset($rows[$url])) {
                $rows[$url] = true;
                if (count($rows) >= $limit) {
                    break;
                }
            }
        }
        return array_keys($rows);
    }

    public static function indexes(string $xml, array $allowedHosts, int $limit): array
    {
        if ($xml === '' || strlen($xml) > 1048576 || substr_count(strtolower($xml), '<sitemap') > 512) {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return [];
        }
        $xpath = new DOMXPath($dom);
        $rows = [];
        foreach ($xpath->query('//*[local-name()="sitemap"]/*[local-name()="loc"]') ?: [] as $node) {
            $url = trim($node->textContent);
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== '' && in_array($host, $allowedHosts, true) && !isset($rows[$url])) {
                $rows[$url] = true;
                if (count($rows) >= $limit) {
                    break;
                }
            }
        }
        return array_keys($rows);
    }
}

final class HtmlLinkExtractor
{
    public static function links(string $html, string $baseUrl, array $allowedHosts, int $limit): array
    {
        if ($html === '' || strlen($html) > 1048576 || $limit < 1) {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $loaded = $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return [];
        }
        $xpath = new DOMXPath($dom);
        $rows = [];
        foreach ($xpath->query('//a[@href]/@href') ?: [] as $node) {
            $url = self::absolute($baseUrl, html_entity_decode(trim($node->nodeValue), ENT_QUOTES | ENT_HTML5));
            if ($url === null) {
                continue;
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $path = strtolower((string) parse_url($url, PHP_URL_PATH));
            if (!in_array($host, $allowedHosts, true) || preg_match('/\.(?:jpe?g|png|gif|webp|svg|pdf|zip|rar|7z|mp4|mp3|xml|json|css|js|woff2?)$/i', $path)) {
                continue;
            }
            $rows[$url] = true;
            if (count($rows) >= $limit) {
                break;
            }
        }
        return array_keys($rows);
    }

    private static function absolute(string $baseUrl, string $href): ?string
    {
        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(?:mailto|tel|javascript|data):/i', $href)) {
            return null;
        }
        $base = parse_url($baseUrl);
        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            $href = $base['scheme'] . ':' . $href;
        } elseif (!preg_match('~^https?://~i', $href)) {
            $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
            if (str_starts_with($href, '/')) {
                $href = $origin . $href;
            } else {
                $basePath = (string) ($base['path'] ?? '/');
                $directory = str_ends_with($basePath, '/') ? $basePath : preg_replace('~/[^/]*$~', '/', $basePath);
                $href = $origin . $directory . $href;
            }
        }
        $parts = parse_url($href);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        $segments = [];
        foreach (explode('/', (string) ($parts['path'] ?? '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }
        $path = '/' . implode('/', $segments);
        if (str_ends_with((string) ($parts['path'] ?? ''), '/') && $path !== '/') {
            $path .= '/';
        }
        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path;
    }
}
