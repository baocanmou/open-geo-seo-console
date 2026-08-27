<?php

declare(strict_types=1);

use OpenGeo\FetchResult;
use OpenGeo\GeoCore;
use OpenGeo\PasswordPolicy;
use OpenGeo\RobotsPolicy;
use OpenGeo\Security;
use OpenGeo\SafeHttpClient;
use OpenGeo\SitemapReader;
use OpenGeo\SiteAuditor;

require dirname(__DIR__) . '/app/Core.php';
require dirname(__DIR__) . '/app/Crawler.php';
require dirname(__DIR__) . '/app/GeoCore.php';
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

$test('password policy keeps a strong public default and supports a bounded private override', static function () use ($assert): void {
    putenv('PASSWORD_MIN_LENGTH=');
    $assert(PasswordPolicy::minimumLength() === 16);

    putenv('PASSWORD_MIN_LENGTH=9');
    $assert(PasswordPolicy::minimumLength() === 9);
    PasswordPolicy::validate('A1b2C3d4E', 'bcm');
    putenv('PASSWORD_MIN_LENGTH=');
});

$test('password policy rejects short, oversized and username-equal values', static function () use ($assert): void {
    putenv('PASSWORD_MIN_LENGTH=16');
    foreach (['short-value', str_repeat('a', 201), 'bcm'] as $blocked) {
        try {
            PasswordPolicy::validate($blocked, 'bcm');
            $assert(false, 'Unsafe password was accepted.');
        } catch (OpenGeo\HttpError $error) {
            $assert($error->status === 422);
        }
    }
    putenv('PASSWORD_MIN_LENGTH=');
});

$test('password change route verifies CSRF, current password and revokes other sessions', static function () use ($assert): void {
    $apiSource = file_get_contents(dirname(__DIR__) . '/app/Api.php');
    $coreSource = file_get_contents(dirname(__DIR__) . '/app/Core.php');
    $assert(is_string($apiSource) && is_string($coreSource));
    $assert(str_contains($apiSource, "'/account/password'"));
    $assert(str_contains($apiSource, 'Security::verifyUnsafeRequest()'));
    $assert(str_contains($coreSource, 'password_verify($currentPassword'));
    $assert(str_contains($coreSource, "'password-change-account|'"));
    $assert(str_contains($coreSource, "'password-change-pair|'"));
    $assert(str_contains($coreSource, "'password-change-ip|'"));
    $assert(str_contains($coreSource, '$accountFailures >= 10'));
    $assert(str_contains($coreSource, 'session_version = session_version + 1'));
    $assert(str_contains($coreSource, 'Security::refreshAuthenticatedSession'));
    $assert(str_contains($coreSource, "Activity::record('auth.password_changed'"));
});

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
    $assert(str_contains($cliSource, 'INTERVAL 24 HOUR'));
    $assert(!str_contains($cliSource, 'INTERVAL 6 HOUR'));
    $assert(substr_count($cliSource, 'common_crawl_global_error') >= 2);
});

$test('site audit worker enforces a bounded global crawl cooldown', static function () use ($assert, $cliSource): void {
    $assert(str_contains($cliSource, "AUDIT_GLOBAL_MIN_INTERVAL_SECONDS', 45"));
    $assert(str_contains($cliSource, 'INTERVAL {$globalInterval} SECOND'));
    $assert(str_contains($cliSource, 'global crawl cooldown'));
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

$test('crawler avoids WAF-sensitive content-encoding negotiation', static function () use ($assert): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/Crawler.php');
    $assert(is_string($source));
    $assert(!str_contains($source, 'CURLOPT_ENCODING'));
    $assert(!str_contains($source, 'Accept-Encoding: identity'));
    $assert(str_contains($source, 'Mozilla/5.0 (compatible; OpenGEO-Audit/1.0;'));
    $assert(str_contains($source, "CRAWL_MIN_INTERVAL_MS', 1000"));
    $assert(str_contains($source, "AUDIT_MAX_SECONDS', 120"));
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

$test('Common Crawl 404 response body is normalized to an empty observation', static function () use ($assert): void {
    $summary = OpenGeo\PublicDataParser::commonCrawlResponseSummary(
        404,
        '<html><body>No Captures found</body></html>',
        ['www.example.com', 'example.com']
    );
    $assert($summary['indexed_pages'] === 0);
    $assert($summary['invalid_rows'] === 0);
    $assert($summary['latest_capture_at'] === null);
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

$test('rolling audit rotation keeps the homepage and advances through unique pages', static function () use ($assert): void {
    $urls = ['https://example.com/', 'https://example.com/a', 'https://example.com/b', 'https://example.com/c', 'https://example.com/d', 'https://example.com/a'];
    $rotated = SiteAuditor::rotateUrlCandidates($urls, 3);
    $assert($rotated === ['https://example.com/', 'https://example.com/d', 'https://example.com/a', 'https://example.com/b', 'https://example.com/c']);
    $assert(SiteAuditor::rotateUrlCandidates($urls, 7) === $rotated);
});

$test('rolling GEO aggregation is bounded, versioned and opt-in configurable', static function () use ($assert): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/Auditor.php');
    $apiSource = file_get_contents(dirname(__DIR__) . '/app/Api.php');
    $assert(is_string($source) && is_string($apiSource));
    $assert(str_contains($source, "AUDIT_PAGE_BATCH', 4"));
    $assert(str_contains($source, "GEO_ROLLING_WINDOW_DAYS', 30"));
    $assert(str_contains($source, 'algorithm_version = ?'));
    $assert(str_contains($source, 'formula_hash = ?'));
    $assert(str_contains($apiSource, 'algorithm_version = ?'));
    $assert(str_contains($apiSource, 'formula_hash = ?'));
    $assert(GeoCore::VERSION === '2.2.0');
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
    $assert(in_array('geo_core_answerability', $codes, true));
    $assert($technical < 100 && $geo < 100);
});

$test('GEO core rewards answerable, evidenced and machine-readable content', static function () use ($assert): void {
    $html = <<<'HTML'
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>企业品牌定位服务指南</title><link rel="canonical" href="https://example.com/service"><link rel="alternate" hreflang="en" href="https://example.com/en/service"><script type="application/ld+json">{"@context":"https://schema.org","@type":"Service","name":"品牌定位咨询","url":"https://example.com/service","provider":{"@type":"Organization","name":"示例咨询机构","url":"https://example.com/","sameAs":["https://example.org/profile"],"contactPoint":{"@type":"ContactPoint","telephone":"400-000-0000"}},"areaServed":"中国"}</script></head><body><main><article><h1 id="guide">企业品牌定位服务指南</h1><p>什么是品牌定位？品牌定位是指企业在目标顾客心智中建立清晰差异的方法。</p><h2 id="steps">如何开展品牌定位？</h2><ol><li>研究顾客</li><li>分析竞争</li><li>形成证据</li></ol><h2 id="evidence">证据与适用边界</h2><p>作者：示例研究组。发布日期：2026-08-20。更新时间：2026-08-26。数据来源：<a href="https://www.wipo.int/">WIPO</a>。联系我们获取公开方法说明。</p><h2 id="faq">常见问题</h2><p>是否适用于小企业？需要结合企业阶段判断。</p></article></main></body></html>
HTML;
    $dom = new DOMDocument();
    $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    $body = trim((string) $xpath->query('//body')->item(0)?->textContent);
    $analysis = GeoCore::analyzeDocument($xpath, [
        'url' => 'https://example.com/service', 'http_status' => 200, 'indexable' => true,
        'canonical_url' => 'https://example.com/service', 'title' => '企业品牌定位服务指南', 'description' => '公开指南',
        'h1_count' => 1, 'language' => 'zh-CN', 'body_text' => $body, 'word_count' => 520, 'missing_alt' => 0,
    ]);
    $assert($analysis['score'] >= 75, 'Rich evidence page should score at least 75.');
    $assert($analysis['dimensions']['entity_clarity'] >= 80);
    $assert($analysis['dimensions']['answerability'] >= 65);
    $assert($analysis['dimensions']['evidence_trust'] >= 75);
    $assert(in_array('service', $analysis['intents'], true));
    $assert($analysis['formula_hash'] === GeoCore::formulaHash());
});

$test('GEO core ignores keyword stuffing and caps non-indexable pages', static function () use ($assert): void {
    $html = '<html lang="zh-CN"><head><title>品牌服务</title></head><body><h1>品牌服务</h1><p>' . str_repeat('品牌服务 ', 600) . '</p></body></html>';
    $dom = new DOMDocument();
    $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    $analysis = GeoCore::analyzeDocument($xpath, [
        'url' => 'https://example.com/stuffed', 'http_status' => 200, 'indexable' => false,
        'canonical_url' => '', 'title' => '品牌服务', 'description' => '', 'h1_count' => 1,
        'language' => 'zh-CN', 'body_text' => str_repeat('品牌服务 ', 600), 'word_count' => 600, 'missing_alt' => 0,
    ]);
    $assert($analysis['score'] <= 45, 'Noindex page must remain capped.');
    $assert($analysis['dimensions']['evidence_trust'] <= 20, 'Keyword repetition must not create trust evidence.');
    $assert($analysis['dimensions']['entity_clarity'] <= 20, 'Keyword repetition must not create an entity graph.');
});

$test('GEO core rejects malformed JSON-LD as entity evidence', static function () use ($assert): void {
    $html = '<html lang="en"><head><title>Example</title><script type="application/ld+json">{"@type":"Organization",</script></head><body><main><h1>Example</h1><p>Plain public page.</p></main></body></html>';
    $dom = new DOMDocument();
    $dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    $analysis = GeoCore::analyzeDocument($xpath, [
        'url' => 'https://example.com/', 'http_status' => 200, 'indexable' => true,
        'canonical_url' => '', 'title' => 'Example', 'description' => '', 'h1_count' => 1,
        'language' => 'en', 'body_text' => 'Plain public page.', 'word_count' => 3, 'missing_alt' => 0,
    ]);
    $assert($analysis['signals']['invalid_schema_count'] === 1);
    $assert($analysis['signals']['schema_types'] === []);
    $assert($analysis['primary_entities'] === []);
});

$test('GEO site aggregation separates robots policy from controlled HTTP probes', static function () use ($assert): void {
    $dimensions = [
        'retrievability' => 90, 'entity_clarity' => 80, 'answerability' => 75, 'evidence_trust' => 70,
        'citation_readiness' => 80, 'freshness' => 70, 'localization' => 70, 'machine_readability' => 80,
    ];
    $pages = [
        ['url' => 'https://example.com/', 'score' => 80, 'dimensions' => $dimensions, 'content_hash' => 'same', 'primary_entities' => ['Example'], 'intents' => ['home']],
        ['url' => 'https://example.com/a', 'score' => 80, 'dimensions' => $dimensions, 'content_hash' => 'same', 'primary_entities' => ['Example'], 'intents' => ['service']],
    ];
    $snapshot = GeoCore::aggregate($pages, [
        ['purpose' => 'search', 'robots_allowed' => 1, 'http_status' => 200, 'access_allowed' => 1],
        ['purpose' => 'retrieval', 'robots_allowed' => 0, 'http_status' => null, 'access_allowed' => 0],
    ]);
    $assert($snapshot['coverage']['duplicate_page_count'] === 1);
    $assert($snapshot['coverage']['bot_policy_allow_rate'] === 50.0);
    $assert($snapshot['coverage']['bot_http_probe_rate'] === 100.0);
    $assert($snapshot['coverage']['bot_http_probe_count'] === 1);
    $assert($snapshot['coverage']['bot_access_rate'] === 100.0);
    $assert($snapshot['coverage']['entity_consistency'] === 100.0);
    $assert(in_array('service', array_keys($snapshot['coverage']['detected_intents']), true));
    $assert(count($snapshot['recommendations']) > 0);

    $policyOnly = GeoCore::aggregate($pages, [
        ['purpose' => 'search', 'robots_allowed' => 1, 'http_status' => null, 'access_allowed' => 1],
        ['purpose' => 'retrieval', 'robots_allowed' => 0, 'http_status' => null, 'access_allowed' => 0],
    ]);
    $assert($policyOnly['coverage']['bot_policy_allow_rate'] === 50.0);
    $assert($policyOnly['coverage']['bot_http_probe_rate'] === null);
    $assert($policyOnly['coverage']['bot_http_probe_count'] === 0);
    $assert($policyOnly['coverage']['bot_access_rate'] === null);
});

$failed = array_filter($tests, static fn(array $row): bool => !$row['ok']);
foreach ($tests as $row) {
    fwrite(STDOUT, ($row['ok'] ? '[PASS] ' : '[FAIL] ') . $row['name'] . ($row['ok'] ? '' : ': ' . $row['error']) . "\n");
}
exit($failed === [] ? 0 : 1);
