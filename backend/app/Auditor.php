<?php

declare(strict_types=1);

namespace OpenGeo;

use DOMDocument;
use DOMXPath;
use PDO;
use RuntimeException;
use Throwable;

final class SiteAuditor
{
    private const BOTS = [
        ['Googlebot', 'search', 'Google 搜索抓取', 'google_search_console', true],
        ['Bingbot', 'search', 'Bing / Copilot 搜索抓取', 'bing_webmaster', true],
        ['Baiduspider', 'search', '百度搜索抓取', 'baidu_search_resource', true],
        ['YandexBot', 'search', 'Yandex 搜索抓取', 'yandex_webmaster', true],
        ['360Spider', 'search', '360 搜索抓取', '360_search', true],
        ['Sogou web spider', 'search', '搜狗搜索抓取', 'sogou_search', true],
        ['YisouSpider', 'search', '神马搜索抓取', 'shenma_search', true],
        ['DuckDuckBot', 'search', 'DuckDuckGo 搜索抓取', 'duckduckgo_search', true],
        ['Applebot', 'search', 'Apple 搜索、Siri 与 Spotlight 抓取', 'apple_search', true],
        ['PetalBot', 'search', 'Petal Search 抓取', 'petal_search', true],
        ['OAI-SearchBot', 'retrieval', 'ChatGPT 搜索发现', 'openai_search', true],
        ['ChatGPT-User', 'retrieval', 'ChatGPT 用户发起访问', 'openai_search', true],
        ['Claude-SearchBot', 'retrieval', 'Claude 搜索发现', 'claude', true],
        ['Claude-User', 'retrieval', 'Claude 用户发起访问', 'claude', true],
        ['PerplexityBot', 'retrieval', 'Perplexity 搜索索引', 'perplexity', true],
        ['Perplexity-User', 'retrieval', 'Perplexity 用户发起访问', 'perplexity', true],
        ['GPTBot', 'training', 'OpenAI 训练控制', 'openai_search', true],
        ['ClaudeBot', 'training', 'Anthropic 训练控制', 'claude', true],
        ['Bytespider', 'training', '字节系训练抓取', 'doubao', true],
        ['CCBot', 'training', 'Common Crawl 数据集抓取', 'common_crawl', true],
        ['Google-Extended', 'extended', 'Google AI 扩展用途控制', 'google_gemini', false],
        ['Applebot-Extended', 'extended', 'Apple 生成式模型用途控制', 'apple_search', false],
    ];

    public function __construct(private readonly PDO $db, private readonly SafeHttpClient $http) {}

    public function run(string $auditPublicId): void
    {
        $this->http->beginAudit();
        $stmt = $this->db->prepare('SELECT ar.*, s.name, s.domain, s.canonical_url, s.max_pages FROM audit_runs ar JOIN sites s ON s.id = ar.site_id WHERE ar.public_id = ? LIMIT 1');
        $stmt->execute([$auditPublicId]);
        $run = $stmt->fetch();
        if (!$run) {
            throw new RuntimeException('Audit run not found.');
        }
        if (!in_array($run['status'], ['queued', 'running'], true)) {
            return;
        }
        $this->db->prepare("UPDATE audit_runs SET status = 'running', started_at = COALESCE(started_at, NOW()) WHERE id = ?")->execute([$run['id']]);
        try {
            $this->execute($run);
        } catch (Throwable $error) {
            $this->db->prepare("UPDATE audit_runs SET status = 'failed', error_message = ?, completed_at = NOW() WHERE id = ?")
                ->execute([mb_substr($error->getMessage(), 0, 500), $run['id']]);
            throw $error;
        }
    }

    private function execute(array $run): void
    {
        $siteId = (int) $run['site_id'];
        $runId = (int) $run['id'];
        $allowedHosts = [$run['domain']];
        $aliasStmt = $this->db->prepare('SELECT hostname FROM site_aliases WHERE site_id = ?');
        $aliasStmt->execute([$siteId]);
        $allowedHosts = array_values(array_unique(array_merge($allowedHosts, array_column($aliasStmt->fetchAll(), 'hostname'))));
        $origin = rtrim((string) $run['canonical_url'], '/');
        $findings = [];

        $robotsContent = '';
        try {
            $robotsResult = $this->http->get($origin . '/robots.txt', $allowedHosts, null, 262144);
            if ($robotsResult->status < 400 && str_contains(strtolower($robotsResult->contentType()), 'text')) {
                $robotsContent = $robotsResult->body;
            }
        } catch (AuditBudgetExceeded $error) {
            throw $error;
        } catch (Throwable $error) {
            $this->addFinding($findings, 'robots_unreachable', 'technical', 'medium', 'robots.txt 无法读取', mb_substr($error->getMessage(), 0, 220), '确认 robots.txt 可公开访问，并检查 CDN、防火墙与 TLS 配置。', $origin . '/robots.txt');
        }
        $robots = RobotsPolicy::parse($robotsContent);
        $this->checkBots($runId, $siteId, $origin, $allowedHosts, $robots, $findings);

        $urls = [$origin . '/'];
        $pendingSitemaps = $robots->sitemaps();
        if ($pendingSitemaps === []) {
            $pendingSitemaps[] = $origin . '/sitemap.xml';
            $pendingSitemaps[] = $origin . '/sitemap_index.xml';
        }
        $seenSitemaps = [];
        while ($pendingSitemaps !== [] && count($seenSitemaps) < 8 && count($urls) < (int) $run['max_pages']) {
            $sitemapUrl = array_shift($pendingSitemaps);
            if (isset($seenSitemaps[$sitemapUrl])) {
                continue;
            }
            $seenSitemaps[$sitemapUrl] = true;
            try {
                $sitemap = $this->http->get($sitemapUrl, $allowedHosts, null, 1048576);
                if ($sitemap->status < 400) {
                    $remaining = max(1, (int) $run['max_pages'] - count($urls));
                    $urls = array_merge($urls, SitemapReader::urls($sitemap->body, $allowedHosts, $remaining));
                    foreach (SitemapReader::indexes($sitemap->body, $allowedHosts, 8) as $childSitemap) {
                        if (!isset($seenSitemaps[$childSitemap])) {
                            $pendingSitemaps[] = $childSitemap;
                        }
                    }
                } else {
                    $this->addFinding($findings, 'sitemap_unavailable', 'technical', 'medium', 'Sitemap 无法被采集器读取', "HTTP {$sitemap->status}: {$sitemapUrl}", '检查 CDN/WAF 对受控采集器的访问策略，或确保首页内链可完整发现重要页面。', $sitemapUrl);
                }
            } catch (AuditBudgetExceeded $error) {
                throw $error;
            } catch (Throwable $error) {
                $this->addFinding($findings, 'sitemap_unavailable', 'technical', 'medium', 'Sitemap 无法被采集器读取', mb_substr($error->getMessage(), 0, 220), '检查 Sitemap 地址、TLS、CDN/WAF 与公网可访问性。', $sitemapUrl);
            }
        }
        $urls = array_slice(array_values(array_unique($urls)), 0, max(1, (int) $run['max_pages']));
        $technicalScores = [];
        $geoScores = [];
        $audited = 0;
        $knownUrls = array_fill_keys($urls, true);
        for ($position = 0; $position < count($urls) && $position < (int) $run['max_pages']; $position++) {
            $this->http->assertAuditBudget();
            $url = $urls[$position];
            try {
                $result = $this->http->get($url, $allowedHosts);
                if ($result->status >= 300 && $result->status < 400) {
                    $this->addFinding($findings, 'redirect_unresolved', 'technical', 'medium', '页面重定向未收敛', "HTTP {$result->status}: {$url}", '将站内 URL 直接指向最终规范地址，并检查重定向链。', $url);
                }
                if (str_contains(strtolower($result->contentType()), 'html')) {
                    $remainingLinks = max(0, (int) $run['max_pages'] - count($urls));
                    foreach (HtmlLinkExtractor::links($result->body, $result->finalUrl, $allowedHosts, $remainingLinks) as $link) {
                        $path = (string) (parse_url($link, PHP_URL_PATH) ?: '/');
                        if (!isset($knownUrls[$link]) && $robots->allows('OPENGEO-Audit', $path, function (): void {
                            $this->http->assertAuditBudget();
                        })) {
                            $knownUrls[$link] = true;
                            $urls[] = $link;
                        }
                    }
                }
                [$page, $pageFindings, $technicalScore, $geoScore] = $this->analyzePage($result);
                $this->storePage($runId, $siteId, $page);
                foreach ($pageFindings as $finding) {
                    $this->addFinding($findings, ...$finding);
                }
                if ($technicalScore !== null) {
                    $technicalScores[] = $technicalScore;
                    $geoScores[] = $geoScore;
                    $audited++;
                }
            } catch (AuditBudgetExceeded $error) {
                throw $error;
            } catch (Throwable $error) {
                $this->addFinding($findings, 'page_fetch_failed', 'technical', 'high', '页面采集失败', mb_substr($error->getMessage(), 0, 240), '检查 DNS、TLS、CDN、防火墙与页面响应，确认公网可稳定访问。', $url);
            }
        }

        foreach (['llms.txt', 'llms-full.txt'] as $llmsFilename) {
            $llmsUrl = $origin . '/' . $llmsFilename;
            $codePrefix = str_replace(['.', '-'], '_', $llmsFilename);
            try {
                $llms = $this->http->get($llmsUrl, $allowedHosts, null, 262144);
                if ($llms->status >= 200 && $llms->status < 400 && trim($llms->body) !== '') {
                    continue;
                }
                if (in_array($llms->status, [401, 403, 429], true)) {
                    $this->addFinding($findings, $codePrefix . '_probe_blocked', 'security', 'info', "{$llmsFilename} collection blocked by a security policy", "HTTP {$llms->status}; this does not prove the file is missing", 'Keep the WAF protection and allow only verified collector identities. Collection access, file existence, indexing, and AI recommendation are separate states.', $llmsUrl);
                } elseif (in_array($llms->status, [404, 410], true)) {
                    $this->addFinding($findings, $codePrefix . '_missing', 'geo', 'info', "{$llmsFilename} was not found", "HTTP {$llms->status}", "Optionally publish {$llmsFilename} at the site root as machine-readable navigation. It does not guarantee ranking or recommendation.", $llmsUrl);
                } elseif ($llms->status >= 400) {
                    $this->addFinding($findings, $codePrefix . '_unreachable', 'technical', 'info', "{$llmsFilename} could not be collected", "HTTP {$llms->status}", 'Check public routing, TLS, CDN, and origin behavior. Do not interpret one failed request as proof that the file is missing.', $llmsUrl);
                } else {
                    $this->addFinding($findings, $codePrefix . '_empty', 'geo', 'info', "{$llmsFilename} is empty", "HTTP {$llms->status}; body=empty", 'Publish accurate machine-readable navigation that stays consistent with public pages.', $llmsUrl);
                }
            } catch (AuditBudgetExceeded $error) {
                throw $error;
            } catch (Throwable $error) {
                $this->addFinding($findings, $codePrefix . '_request_failed', 'technical', 'info', "{$llmsFilename} request failed", mb_substr($error->getMessage(), 0, 220), 'Review DNS, TLS, CDN/WAF, and collector networking. A failed request does not prove the file is missing.', $llmsUrl);
            }
        }

        $this->http->assertAuditBudget();
        $technical = $technicalScores === [] ? null : round(array_sum($technicalScores) / count($technicalScores), 2);
        $geo = $geoScores === [] ? null : round(array_sum($geoScores) / count($geoScores), 2);
        if ($technical !== null && isset($findings['crawler_access_denied'])) {
            $technical = max(0, $technical - 10);
            $geo = $geo === null ? null : max(0, $geo - 10);
        }
        if ($technical !== null && isset($findings['sitemap_unavailable'])) {
            $technical = max(0, $technical - 5);
        }
        $this->storeFindingsAndTasks($runId, $siteId, $findings);
        $this->db->prepare("UPDATE audit_runs SET status = 'completed', pages_discovered = ?, pages_audited = ?, technical_score = ?, geo_score = ?, completed_at = NOW() WHERE id = ?")
            ->execute([count($urls), $audited, $technical, $geo, $runId]);
        $this->db->prepare('UPDATE sites SET technical_score = ?, geo_score = ?, last_audit_at = NOW(), updated_at = NOW() WHERE id = ?')
            ->execute([$technical, $geo, $siteId]);
    }

    private function analyzePage(FetchResult $result): array
    {
        if (strlen($result->body) > 1048576) {
            throw new RuntimeException('HTML response exceeded the parser size limit.');
        }
        $url = $result->finalUrl;
        $page = [
            'url' => $result->requestedUrl,
            'final_url' => $url,
            'http_status' => $result->status,
            'content_type' => $result->contentType(),
            'title_text' => null,
            'meta_description' => null,
            'canonical_url' => null,
            'h1_count' => 0,
            'word_count' => 0,
            'structured_types' => [],
            'indexable' => 1,
            'response_ms' => $result->durationMs,
        ];
        $findings = [];
        $technical = 100;
        $geo = 100;
        if (in_array($result->status, [401, 403, 429], true)) {
            $findings[] = ['crawler_access_denied', 'security', 'medium', '受控采集器访问被拒绝', "HTTP {$result->status}: {$url}", '检查 CDN/WAF 的 User-Agent、频率与来源策略；该状态仅证明本次采集受限，不直接等同于搜索引擎无法访问。', $url];
            return [$page, $findings, null, null];
        }
        if ($result->status >= 400) {
            $severity = $result->status >= 500 ? 'critical' : 'high';
            $findings[] = ['http_error', 'technical', $severity, "页面返回 HTTP {$result->status}", $url, '修复失效页面或将内部链接、Sitemap 更新为有效的最终 URL。', $url];
            return [$page, $findings, 45, 45];
        }
        if (!str_contains(strtolower($result->contentType()), 'html')) {
            $findings[] = ['non_html_url', 'technical', 'low', 'Sitemap 包含非 HTML URL', $result->contentType(), '将页面型 Sitemap 限定为可索引 HTML 规范页面。', $url];
            return [$page, $findings, 88, 70];
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $loaded = $dom->loadHTML($result->body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            $findings[] = ['html_parse_failed', 'technical', 'high', 'HTML 无法稳定解析', 'DOM 解析失败', '修复不完整标签、异常编码或网关注入内容。', $url];
            return [$page, $findings, 55, 55];
        }
        $xpath = new DOMXPath($dom);
        $title = trim((string) ($xpath->query('//title')->item(0)?->textContent ?? ''));
        $description = trim((string) ($xpath->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content')->item(0)?->nodeValue ?? ''));
        $canonical = trim((string) ($xpath->query('//link[contains(concat(" ", translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"), " "), " canonical ")]/@href')->item(0)?->nodeValue ?? ''));
        $h1Count = $xpath->query('//h1')?->length ?? 0;
        $robotsMeta = strtolower(trim((string) ($xpath->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="robots"]/@content')->item(0)?->nodeValue ?? '')));
        $language = trim((string) ($xpath->query('/html/@lang')->item(0)?->nodeValue ?? ''));
        $bodyText = preg_replace('/\s+/u', ' ', trim((string) ($xpath->query('//body')->item(0)?->textContent ?? '')));
        $wordCount = max(count(preg_split('/\s+/u', $bodyText, -1, PREG_SPLIT_NO_EMPTY) ?: []), (int) ceil(mb_strlen(preg_replace('/\s+/u', '', $bodyText)) / 2));
        $types = [];
        foreach ($xpath->query('//script[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]') ?: [] as $script) {
            if (preg_match_all('/"@type"\s*:\s*"([^"]+)"/u', $script->textContent, $matches)) {
                $types = array_merge($types, $matches[1]);
            }
        }
        $types = array_values(array_unique($types));
        $images = $xpath->query('//img');
        $missingAlt = 0;
        foreach ($images ?: [] as $image) {
            if (!$image->hasAttribute('alt') || trim($image->getAttribute('alt')) === '') {
                $missingAlt++;
            }
        }
        $page = array_merge($page, [
            'title_text' => $title ?: null,
            'meta_description' => $description ?: null,
            'canonical_url' => $canonical ?: null,
            'h1_count' => $h1Count,
            'word_count' => $wordCount,
            'structured_types' => $types,
            'indexable' => str_contains($robotsMeta, 'noindex') ? 0 : 1,
        ]);
        if ($title === '') {
            $findings[] = ['missing_title', 'technical', 'high', '页面缺少 title', '未检测到 <title>', '为每个可索引页面提供唯一且准确的标题。', $url];
            $technical -= 12;
        } elseif (mb_strlen($title) < 8 || mb_strlen($title) > 70) {
            $findings[] = ['title_length', 'technical', 'low', '页面标题长度需复核', '字符数：' . mb_strlen($title), '让标题简洁、唯一，并准确表达页面主题；避免机械截断。', $url];
            $technical -= 3;
        }
        if ($description === '') {
            $findings[] = ['missing_description', 'technical', 'medium', '页面缺少 meta description', '未检测到 description', '提供准确摘要，帮助搜索结果理解页面内容。', $url];
            $technical -= 6;
        }
        if ($canonical === '') {
            $findings[] = ['missing_canonical', 'technical', 'medium', '页面缺少 canonical', '未检测到规范地址声明', '为可索引页面声明自引用或正确的规范 URL。', $url];
            $technical -= 7;
        }
        if ($h1Count === 0) {
            $findings[] = ['missing_h1', 'technical', 'medium', '页面缺少 H1', 'H1 数量：0', '为主内容提供清晰且与主题一致的一级标题。', $url];
            $technical -= 5;
        } elseif ($h1Count > 1) {
            $findings[] = ['multiple_h1', 'technical', 'low', '页面包含多个 H1', "H1 数量：{$h1Count}", '确认页面标题层级清楚，主标题保持单一。', $url];
            $technical -= 2;
        }
        if (str_contains($robotsMeta, 'noindex')) {
            $findings[] = ['noindex_page', 'technical', 'high', '页面声明 noindex', $robotsMeta, '若页面需要搜索曝光，移除 noindex 并复核 HTTP X-Robots-Tag。', $url];
            $technical -= 18;
        }
        if ($language === '') {
            $findings[] = ['missing_lang', 'technical', 'low', 'HTML 缺少 lang', '未检测到语言声明', '声明页面主要语言，辅助搜索与无障碍工具理解内容。', $url];
            $technical -= 2;
        }
        if (($images?->length ?? 0) > 0 && $missingAlt > 0) {
            $findings[] = ['missing_image_alt', 'technical', 'low', '部分图片缺少替代文本', "{$missingAlt}/{$images->length} 张图片缺少 alt", '为承载信息的图片提供准确 alt；纯装饰图片使用空 alt。', $url];
            $technical -= min(5, $missingAlt);
        }
        if ($result->durationMs > 3000) {
            $findings[] = ['slow_response', 'performance', 'medium', '页面响应偏慢', "服务端完整响应约 {$result->durationMs}ms", '检查源站、缓存与数据库；该值是本次采集时延，不等同于 Core Web Vitals。', $url];
            $technical -= 5;
        }
        if ($wordCount < 180) {
            $findings[] = ['thin_content', 'geo', 'medium', '页面可引用正文偏少', "估算正文 {$wordCount} 词", '补充清晰定义、适用场景、步骤、证据与常见问题，避免无意义扩写。', $url];
            $geo -= 12;
        }
        $entityTypes = array_intersect($types, ['Organization', 'LocalBusiness', 'Product', 'Service', 'Article', 'NewsArticle']);
        if ($entityTypes === []) {
            $findings[] = ['missing_entity_schema', 'geo', 'medium', '缺少核心实体结构化数据', $types === [] ? '未检测到 JSON-LD 类型' : '已检测：' . implode(', ', $types), '按页面真实内容补充 Organization、Service、Product 或 Article 等合适类型。', $url];
            $geo -= 10;
        }
        if ($types === []) {
            $technical -= 4;
        }
        $headingCount = ($xpath->query('//h2')?->length ?? 0) + ($xpath->query('//h3')?->length ?? 0);
        if ($wordCount >= 300 && $headingCount < 2) {
            $findings[] = ['weak_content_structure', 'geo', 'low', '长内容缺少清晰分段', "正文约 {$wordCount} 词，H2/H3 共 {$headingCount} 个", '用问题式或主题式标题组织定义、比较、步骤与结论，便于人和检索系统定位答案。', $url];
            $geo -= 5;
        }
        $hasAuthority = preg_match('/(作者|发布日期|更新时间|关于我们|联系方式|资质|来源|参考)/u', $bodyText) === 1;
        if ($wordCount >= 300 && !$hasAuthority) {
            $findings[] = ['weak_authority_signals', 'geo', 'low', '权威与来源信号不足', '未发现作者、日期、来源、资质或联系方式等常见信号', '根据页面类型补充真实作者、更新时间、来源依据、主体与联系方式。', $url];
            $geo -= 5;
        }
        return [$page, $findings, max(0, $technical), max(0, $geo)];
    }

    private function checkBots(int $runId, int $siteId, string $origin, array $allowedHosts, RobotsPolicy $robots, array &$findings): void
    {
        $insert = $this->db->prepare('INSERT INTO bot_checks (audit_run_id, site_id, bot_name, purpose, purpose_label, robots_allowed, http_status, access_allowed, evidence, checked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $touchIntegration = $this->db->prepare("UPDATE integrations SET last_sync_at = NOW(), updated_at = NOW() WHERE integration_key = ? AND data_source = 'public'");
        foreach (self::BOTS as [$bot, $purpose, $label, $integrationKey, $probeHttp]) {
            $this->http->assertAuditBudget();
            $allowed = $robots->allows($bot, '/', function (): void {
                $this->http->assertAuditBudget();
            });
            $status = null;
            if ($probeHttp) {
                try {
                    $result = $this->http->get($origin . '/', $allowedHosts, $bot . '/1.0 (+https://github.com/yht0912/open-geo-seo-console)', 131072);
                    $status = $result->status;
                } catch (AuditBudgetExceeded $error) {
                    throw $error;
                } catch (Throwable) {
                    $status = 0;
                }
            }
            $accessible = $allowed && (!$probeHttp || ($status >= 200 && $status < 400));
            $httpEvidence = $probeHttp ? ($status ?: 'failed') : 'not-applicable';
            $evidence = sprintf('robots=%s; http=%s', $allowed ? 'allow' : 'disallow', $httpEvidence);
            $insert->execute([$runId, $siteId, $bot, $purpose, $label, $allowed ? 1 : 0, $status ?: null, $accessible ? 1 : 0, $evidence]);
            $touchIntegration->execute([$integrationKey]);
            if (!$accessible && in_array($purpose, ['search', 'retrieval'], true)) {
                $this->addFinding($findings, 'bot_access_' . strtolower(str_replace('-', '_', $bot)), 'technical', 'high', "{$bot} 无法访问首页", $evidence, '检查 robots.txt、CDN/WAF 与源站响应；先确认该爬虫用途，再决定是否允许。', $origin . '/');
            }
        }
    }

    private function addFinding(array &$bucket, string $code, string $category, string $severity, string $title, string $evidence, string $recommendation, string $url): void
    {
        $code = mb_substr($code, 0, 80);
        $title = mb_substr($title, 0, 255);
        $evidence = mb_substr($evidence, 0, 4000);
        $recommendation = mb_substr($recommendation, 0, 4000);
        $url = mb_substr($url, 0, 1000);
        if (!isset($bucket[$code])) {
            $bucket[$code] = compact('code', 'category', 'severity', 'title', 'evidence', 'recommendation', 'url') + ['count' => 0];
        }
        $bucket[$code]['count']++;
    }

    private function storePage(int $runId, int $siteId, array $page): void
    {
        $structuredTypes = array_slice(array_values(array_unique(array_map(
            static fn(mixed $type): string => mb_substr((string) $type, 0, 160),
            (array) $page['structured_types']
        ))), 0, 100);
        $stmt = $this->db->prepare('INSERT INTO page_audits (audit_run_id, site_id, url, final_url, http_status, content_type, title_text, meta_description, canonical_url, h1_count, word_count, structured_types, indexable, response_ms, captured_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $runId,
            $siteId,
            mb_substr((string) $page['url'], 0, 1000),
            mb_substr((string) $page['final_url'], 0, 1000),
            $page['http_status'],
            mb_substr((string) $page['content_type'], 0, 160),
            $page['title_text'] === null ? null : mb_substr((string) $page['title_text'], 0, 500),
            $page['meta_description'] === null ? null : mb_substr((string) $page['meta_description'], 0, 4000),
            $page['canonical_url'] === null ? null : mb_substr((string) $page['canonical_url'], 0, 1000),
            $page['h1_count'],
            $page['word_count'],
            json_encode($structuredTypes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $page['indexable'],
            $page['response_ms'],
        ]);
    }

    private function storeFindingsAndTasks(int $runId, int $siteId, array $findings): void
    {
        $findingStmt = $this->db->prepare('INSERT INTO findings (public_id, audit_run_id, site_id, fingerprint, code, category, severity, title, evidence, recommendation, affected_url, affected_count, status, detected_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'open\', NOW())');
        $taskExists = $this->db->prepare("SELECT id FROM tasks WHERE site_id = ? AND title = ? AND status <> 'resolved' LIMIT 1");
        $taskStmt = $this->db->prepare("INSERT INTO tasks (public_id, site_id, finding_id, title, description, category, priority, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'open', NOW(), NOW())");
        foreach ($findings as $finding) {
            $publicId = Security::uuid();
            $fingerprint = hash('sha256', $siteId . '|' . $finding['code'] . '|' . $finding['url']);
            $findingStmt->execute([$publicId, $runId, $siteId, $fingerprint, $finding['code'], $finding['category'], $finding['severity'], $finding['title'], $finding['evidence'], $finding['recommendation'], $finding['url'], $finding['count']]);
            $findingId = (int) $this->db->lastInsertId();
            if (in_array($finding['severity'], ['critical', 'high'], true)) {
                $taskExists->execute([$siteId, $finding['title']]);
                if (!$taskExists->fetchColumn()) {
                    $taskStmt->execute([Security::uuid(), $siteId, $findingId, $finding['title'], $finding['recommendation'], $finding['category'], $finding['severity']]);
                }
            }
        }
        $resolveStmt = $this->db->prepare("UPDATE tasks t JOIN findings old_finding ON old_finding.id = t.finding_id LEFT JOIN findings current_finding ON current_finding.audit_run_id = ? AND current_finding.site_id = t.site_id AND current_finding.code = old_finding.code SET t.status = 'resolved', t.resolved_at = NOW(), t.updated_at = NOW() WHERE t.site_id = ? AND t.status <> 'resolved' AND old_finding.audit_run_id <> ? AND current_finding.id IS NULL");
        $resolveStmt->execute([$runId, $siteId, $runId]);
    }
}
