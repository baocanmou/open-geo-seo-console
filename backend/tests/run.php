<?php

declare(strict_types=1);

use OpenGeo\FetchResult;
use OpenGeo\RobotsPolicy;
use OpenGeo\Security;
use OpenGeo\SafeHttpClient;
use OpenGeo\SitemapReader;
use OpenGeo\SiteAuditor;

require dirname(__DIR__) . '/app/Core.php';
require dirname(__DIR__) . '/app/Crawler.php';
require dirname(__DIR__) . '/app/Auditor.php';
$publicDataPath = dirname(__DIR__) . '/app/PublicData.php';
if (is_file($publicDataPath)) {
    require $publicDataPath;
}

$cliSource = file_get_contents(dirname(__DIR__) . "/cli.php");
if (!is_string($cliSource) || str_contains($cliSource, "SKIP LOCKED")) {
    fwrite(STDERR, "[FAIL] scheduler SQL remains incompatible with MySQL 5.7\n");
    exit(1);
}
fwrite(STDOUT, "[PASS] scheduler SQL is compatible with MySQL 5.7\n");

$tests = [];
$test = static function (string $name, callable $callback) use (&$tests): void {
    try {
        $callback();
        $tests[] = ['name' => $name, 'ok' => true];
    } catch (Throwable $error) {
        $tests[] = ['name' => $name, 'ok' => false, 'error' => $error->getMessage()];
    }
};
$assert = static function (bool $condition, string $message = 'Assertion failed'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$test('site audits and Lighthouse collections use separate privilege queues', static function () use ($assert, $cliSource): void {
    $assert(str_contains($cliSource, "job_type = 'site_audit'"));
    $assert(str_contains($cliSource, "case 'public-worker'"));
    $assert(str_contains($cliSource, "job_type = 'public_collect'"));
    $assert(str_contains($cliSource, 'Public Lighthouse worker must run as root.'));
});

$test('public scheduler and bulk queue avoid duplicate active jobs', static function () use ($assert, $cliSource): void {
    $assert(substr_count($cliSource, "job_type = 'public_collect'") >= 2);
    $assert(str_contains($cliSource, "status IN ('queued','running')"));
    $assert(str_contains($cliSource, "JSON_EXTRACT(queued_public.payload, '$.site_id')"));
    $assert(substr_count($cliSource, "status IN ('success','empty')") >= 4);
    $assert(str_contains($cliSource, 'recent_public_error'));
    $assert(str_contains($cliSource, 'INTERVAL 6 HOUR'));
});

$test('UUID v4 format', static function () use ($assert): void {
    $assert((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Security::uuid()));
});

$test('crawler permits global IPs and rejects special-use ranges', static function () use ($assert): void {
    $assert(SafeHttpClient::isGloballyRoutableIp('8.8.8.8'));
    $assert(SafeHttpClient::isGloballyRoutableIp('2606:4700:4700::1111'));
    foreach (['127.0.0.1', '10.0.0.1', '100.64.0.1', '192.0.2.10', '198.18.0.1', '203.0.113.2', '::1', 'fc00::1', 'fe80::1', '64:ff9b::127.0.0.1', '2001::1', '2001:db8::1', '2002:7f00:1::'] as $blocked) {
        $assert(!SafeHttpClient::isGloballyRoutableIp($blocked), "Special-use address was allowed: {$blocked}");
    }
});

$test('crawler rejects trailing-dot hosts before DNS resolution', static function () use ($assert): void {
    $client = new SafeHttpClient();
    $method = new ReflectionMethod(SafeHttpClient::class, 'validateAndResolve');
    $method->setAccessible(true);
    try {
        $method->invoke($client, 'https://example.com./', ['example.com']);
        $assert(false, 'Trailing-dot crawl host was accepted.');
    } catch (ReflectionException $error) {
        throw $error;
    } catch (Throwable $error) {
        $assert(str_contains($error->getMessage(), 'trailing dot'));
    }
});

$test('fetch result keeps requested and final URLs distinct', static function () use ($assert): void {
    $result = new FetchResult('https://example.com/old', 'https://example.com/new', 200, [], '', 42);
    $assert($result->requestedUrl === 'https://example.com/old');
    $assert($result->finalUrl === 'https://example.com/new');
});

$test('audit budget failures have a distinct exception type', static function () use ($assert): void {
    $error = new OpenGeo\AuditBudgetExceeded('budget');
    $assert($error instanceof RuntimeException);
});

$test('public provider policy accepts only pinned HTTPS endpoints', static function () use ($assert): void {
    $assert(OpenGeo\ProviderEndpointPolicy::allows('https://index.commoncrawl.org/collinfo.json'));
    $assert(OpenGeo\ProviderEndpointPolicy::allows('https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=https%3A%2F%2Fexample.com%2F'));
    foreach ([
        'http://index.commoncrawl.org/collinfo.json',
        'https://index.commoncrawl.org.evil.example/collinfo.json',
        'https://user:pass@index.commoncrawl.org/collinfo.json',
        'https://www.googleapis.com:444/pagespeedonline/v5/runPagespeed',
    ] as $blocked) {
        $assert(!OpenGeo\ProviderEndpointPolicy::allows($blocked), "Untrusted provider endpoint was allowed: {$blocked}");
    }
});

$test('Common Crawl 404 is empty evidence while other provider errors remain errors', static function () use ($assert): void {
    $assert(OpenGeo\ProviderStatusPolicy::accepts(200, false));
    $assert(OpenGeo\ProviderStatusPolicy::accepts(404, true));
    $assert(!OpenGeo\ProviderStatusPolicy::accepts(404, false));
    $assert(!OpenGeo\ProviderStatusPolicy::accepts(400, true));
    $assert(!OpenGeo\ProviderStatusPolicy::accepts(504, true));
});

$test('Common Crawl parser keeps only records for the registered host', static function () use ($assert): void {
    $records = implode("\n", [
        '{"url":"https://www.example.com/","timestamp":"20260820112233","status":"200","mime":"text/html"}',
        '{"url":"https://www.example.com/about","timestamp":"20260821112233","status":"200","mime":"text/html"}',
        '{"url":"https://evil.example/","timestamp":"20260822112233","status":"200","mime":"text/html"}',
        '{"url":"https://www.example.com/file.pdf","timestamp":"20260823112233","status":"200","mime":"application/pdf"}',
    ]);
    $summary = OpenGeo\PublicDataParser::commonCrawlSummary($records, ['www.example.com', 'example.com']);
    $assert($summary['indexed_pages'] === 2);
    $assert($summary['latest_capture_at'] === '2026-08-21 11:22:33');
});

$test('PageSpeed parser returns bounded scores and lab metrics', static function () use ($assert): void {
    $payload = json_encode([
        'lighthouseResult' => [
            'fetchTime' => '2026-08-26T10:00:00.000Z',
            'categories' => [
                'performance' => ['score' => 0.91],
                'seo' => ['score' => 0.98],
                'accessibility' => ['score' => 1.7],
                'best-practices' => ['score' => -1],
            ],
            'audits' => [
                'largest-contentful-paint' => ['numericValue' => 2310.4],
                'cumulative-layout-shift' => ['numericValue' => 0.074],
                'interaction-to-next-paint' => ['numericValue' => 189.6],
                'total-blocking-time' => ['numericValue' => 120.2],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    $summary = OpenGeo\PublicDataParser::pageSpeedSummary($payload);
    $assert($summary['performance_score'] === 91.0);
    $assert($summary['seo_score'] === 98.0);
    $assert($summary['accessibility_score'] === 100.0);
    $assert($summary['best_practices_score'] === 0.0);
    $assert($summary['lcp_ms'] === 2310);
    $assert($summary['cls'] === 0.074);
    $assert($summary['inp_ms'] === 190);
    $assert($summary['tbt_ms'] === 120);
});

$test('local Lighthouse parser returns bounded scores and lab metrics', static function () use ($assert): void {
    $payload = json_encode([
        'fetchTime' => '2026-08-26T11:00:00.000Z',
        'finalUrl' => 'https://www.example.com/',
        'categories' => [
            'performance' => ['score' => 0.87],
            'seo' => ['score' => 0.96],
            'accessibility' => ['score' => 1.2],
            'best-practices' => ['score' => -0.2],
        ],
        'audits' => [
            'largest-contentful-paint' => ['numericValue' => 2450.7],
            'cumulative-layout-shift' => ['numericValue' => 0.0812],
            'interaction-to-next-paint' => ['numericValue' => 211.1],
            'total-blocking-time' => ['numericValue' => 138.7],
        ],
    ], JSON_THROW_ON_ERROR);
    $summary = OpenGeo\PublicDataParser::lighthouseSummary($payload, 'https://www.example.com/');
    $assert($summary['runner'] === 'self-hosted-lighthouse');
    $assert($summary['performance_score'] === 87.0);
    $assert($summary['seo_score'] === 96.0);
    $assert($summary['accessibility_score'] === 100.0);
    $assert($summary['best_practices_score'] === 0.0);
    $assert($summary['lcp_ms'] === 2451);
    $assert($summary['cls'] === 0.0812);
    $assert($summary['inp_ms'] === 211);
    $assert($summary['tbt_ms'] === 139);
});

$test('local Lighthouse command policy accepts only registered HTTPS targets', static function () use ($assert): void {
    $assert(OpenGeo\LighthouseTargetPolicy::allows('https://www.example.com/', ['www.example.com', 'example.com']));
    foreach ([
        'http://www.example.com/',
        'https://evil.example/',
        'https://user:pass@www.example.com/',
        'https://www.example.com.:443/',
        'https://www.example.com:444/',
    ] as $blocked) {
        $assert(!OpenGeo\LighthouseTargetPolicy::allows($blocked, ['www.example.com', 'example.com']), "Unsafe Lighthouse target was allowed: {$blocked}");
    }
});

$test('local Lighthouse runner requires live egress verification and avoids shell execution', static function () use ($assert): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/PublicData.php');
    $assert(is_string($source));
    $assert(str_contains($source, 'assertEgressIsolation'));
    $assert(str_contains($source, 'proc_open($command'));
    $assert(!str_contains($source, 'shell_exec('));
    $assert(!str_contains($source, '--no-sandbox'));
});

$test('robots exact group overrides wildcard', static function () use ($assert): void {
    $policy = RobotsPolicy::parse("User-agent: *\nDisallow: /private\n\nUser-agent: OAI-SearchBot\nAllow: /\n");
    $assert(!$policy->allows('Googlebot', '/private/report'));
    $assert($policy->allows('OAI-SearchBot', '/private/report'));
});

$test('robots allow wins equal specificity', static function () use ($assert): void {
    $policy = RobotsPolicy::parse("User-agent: *\nDisallow: /docs\nAllow: /docs\nSitemap: https://example.com/sitemap.xml\n");
    $assert($policy->allows('Bingbot', '/docs'));
    $assert($policy->sitemaps() === ['https://example.com/sitemap.xml']);
});

$test('robots wildcard and end-anchor matching is deterministic', static function () use ($assert): void {
    $policy = RobotsPolicy::parse("User-agent: *\nDisallow: /private/*/draft$\nAllow: /private/public\n");
    $assert(!$policy->allows('AnyBot', '/private/team/draft'));
    $assert($policy->allows('AnyBot', '/private/team/draft/extra'));
    $assert($policy->allows('AnyBot', '/private/public'));
});

$test('robots parser remains bounded with repeated groups', static function () use ($assert): void {
    $content = str_repeat("User-agent: *\nDisallow: /private\n\n", 6000);
    $policy = RobotsPolicy::parse($content);
    $assert(!$policy->allows('AnyBot', '/private/report'));
});

$test('sitemap rejects unregistered hosts', static function () use ($assert): void {
    $xml = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/a</loc></url><url><loc>https://internal.invalid/b</loc></url></urlset>';
    $assert(SitemapReader::urls($xml, ['example.com'], 10) === ['https://example.com/a']);
});


$test('sitemap index keeps registered child maps only', static function () use ($assert): void {
    $xml = '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://example.com/posts.xml</loc></sitemap><sitemap><loc>https://blocked.invalid/private.xml</loc></sitemap></sitemapindex>';
    $assert(SitemapReader::indexes($xml, ['example.com'], 8) === ['https://example.com/posts.xml']);
});

$test('HTML links stay on registered hosts and normalize paths', static function () use ($assert): void {
    $html = '<a href="/services/../cases.html?utm_source=x#top">Case</a><a href="https://blocked.invalid/a">Blocked</a><a href="/brochure.pdf">PDF</a>';
    $assert(OpenGeo\HtmlLinkExtractor::links($html, 'https://example.com/section/page.html', ['example.com'], 10) === ['https://example.com/cases.html']);
});

$test('HTTP 403 is coverage evidence, not a broken-page score', static function () use ($assert): void {
    $auditor = (new ReflectionClass(SiteAuditor::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(SiteAuditor::class, 'analyzePage');
    $method->setAccessible(true);
    $result = new FetchResult('https://example.com/blocked', 'https://example.com/blocked', 403, ['content-type' => 'text/html'], '<html></html>', 80);
    [, $findings, $technical, $geo] = $method->invoke($auditor, $result);
    $assert($findings[0][0] === 'crawler_access_denied');
    $assert($technical === null && $geo === null);
});
$test('HTML audit emits evidence-backed findings', static function () use ($assert): void {
    $auditor = (new ReflectionClass(SiteAuditor::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(SiteAuditor::class, 'analyzePage');
    $method->setAccessible(true);
    $result = new FetchResult('https://example.com/', 'https://example.com/', 200, ['content-type' => 'text/html; charset=utf-8'], '<html><body><p>短内容</p><img src="a.jpg"></body></html>', 120);
    [, $findings, $technical, $geo] = $method->invoke($auditor, $result);
    $codes = array_column($findings, 0);
    $assert(in_array('missing_title', $codes, true));
    $assert(in_array('missing_canonical', $codes, true));
    $assert(in_array('thin_content', $codes, true));
    $assert($technical < 100 && $geo < 100);
});

$failed = array_filter($tests, static fn(array $row): bool => !$row['ok']);
foreach ($tests as $row) {
    fwrite(STDOUT, ($row['ok'] ? '[PASS] ' : '[FAIL] ') . $row['name'] . ($row['ok'] ? '' : ': ' . $row['error']) . "\n");
}
exit($failed === [] ? 0 : 1);
