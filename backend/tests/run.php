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
