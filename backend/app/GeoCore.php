<?php

declare(strict_types=1);

namespace OpenGeo;

use DOMElement;
use DOMXPath;

/**
 * Deterministic, account-free GEO analysis.
 *
 * The core scores only observable public-page signals. It does not infer
 * indexing, ranking, model training, citations, or recommendations.
 */
final class GeoCore
{
    public const VERSION = '2.2.0';

    private const WEIGHTS = [
        'retrievability' => 0.15,
        'entity_clarity' => 0.15,
        'answerability' => 0.18,
        'evidence_trust' => 0.15,
        'citation_readiness' => 0.15,
        'freshness' => 0.10,
        'localization' => 0.05,
        'machine_readability' => 0.07,
    ];

    private const LABELS = [
        'retrievability' => '检索可达性',
        'entity_clarity' => '实体清晰度',
        'answerability' => '答案可提取性',
        'evidence_trust' => '证据与信任',
        'citation_readiness' => '引用就绪度',
        'freshness' => '时效透明度',
        'localization' => '地域与语言',
        'machine_readability' => '机器可读性',
    ];

    private const CORE_ENTITY_TYPES = [
        'Organization', 'LocalBusiness', 'Corporation', 'Person', 'Brand',
        'Product', 'Service', 'Article', 'NewsArticle', 'BlogPosting',
    ];

    public static function formulaHash(): string
    {
        return hash('sha256', json_encode([
            'version' => self::VERSION,
            'weights' => self::WEIGHTS,
            'guardrails' => ['noindex_cap' => 45, 'thin_answer_cap' => 35, 'no_keyword_density_bonus' => true],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function analyzeDocument(DOMXPath $xpath, array $context): array
    {
        $bodyText = self::cleanText((string) ($context['body_text'] ?? ''));
        $wordCount = max(0, (int) ($context['word_count'] ?? 0));
        $title = trim((string) ($context['title'] ?? ''));
        $description = trim((string) ($context['description'] ?? ''));
        $canonical = trim((string) ($context['canonical_url'] ?? ''));
        $indexable = (bool) ($context['indexable'] ?? false);
        $status = (int) ($context['http_status'] ?? 0);
        $h1Count = max(0, (int) ($context['h1_count'] ?? 0));
        $language = trim((string) ($context['language'] ?? ''));

        [$schemaTypes, $schemaNodes, $invalidSchemaCount] = self::schemaData($xpath);
        $headings = self::nodeTexts($xpath, '//h1|//h2|//h3', 160);
        $questionHeadings = count(array_filter($headings, static fn(string $heading): bool => self::looksLikeQuestion($heading)));
        $answerBlocks = self::nodeTexts($xpath, '//main//p|//article//p|//main//dt|//article//dt|//main//summary|//article//summary', 300);
        $questionBlocks = count(array_filter($answerBlocks, static fn(string $block): bool => self::looksLikeQuestion($block)));
        $headingAnchors = $xpath->query('//h1[@id]|//h2[@id]|//h3[@id]')?->length ?? 0;
        $lists = ($xpath->query('//main//ul|//main//ol|//article//ul|//article//ol')?->length ?? 0);
        $tables = ($xpath->query('//main//table|//article//table')?->length ?? 0);
        $semanticContainers = ($xpath->query('//main|//article|//section')?->length ?? 0);
        $hreflangCount = $xpath->query('//link[@hreflang and @href]')?->length ?? 0;
        $externalSourceLinks = self::externalSourceLinkCount($xpath, (string) ($context['url'] ?? ''));
        $images = $xpath->query('//img')?->length ?? 0;
        $missingAlt = max(0, (int) ($context['missing_alt'] ?? 0));

        $properties = self::schemaProperties($schemaNodes);
        $entities = self::primaryEntities($schemaNodes);
        $intents = self::detectIntents((string) ($context['url'] ?? ''), $schemaTypes, $headings, $bodyText);

        $authorSignal = self::hasAnyProperty($properties, ['author', 'creator'])
            || self::matches('/(作者|撰稿|编辑|审校|written by|reviewed by)/iu', $bodyText);
        $publishedSignal = self::hasAnyProperty($properties, ['datePublished'])
            || self::matches('/(发布日期|发布时间|published)\s*[:：]?\s*20\d{2}/iu', $bodyText)
            || ($xpath->query('//time[@datetime]')?->length ?? 0) > 0;
        $modifiedSignal = self::hasAnyProperty($properties, ['dateModified'])
            || self::matches('/(更新日期|最后更新|更新时间|updated)\s*[:：]?\s*20\d{2}/iu', $bodyText);
        $sourceSignal = self::hasAnyProperty($properties, ['citation', 'isBasedOn', 'sameAs'])
            || $externalSourceLinks > 0
            || self::matches('/(参考资料|参考来源|数据来源|资料来源|来源[:：]|references?|sources?)/iu', $bodyText);
        $contactSignal = self::hasAnyProperty($properties, ['contactPoint', 'telephone', 'email', 'address'])
            || self::matches('/(联系我们|联系电话|联系地址|电子邮箱|contact us|phone|email)/iu', $bodyText);
        $definitionSignal = self::matches('/(^|[。！？.!?\s])(什么是|是指|指的是|定义为|means\b|refers to\b|is defined as\b)/iu', $bodyText)
            || count(array_filter($answerBlocks, static fn(string $block): bool => self::matches('/^(什么是|[^。！？.!?]{1,80}(是指|指的是|定义为)|what is\b|[^.!?]{1,120}(means\b|refers to\b|is defined as\b))/iu', $block))) > 0;
        $coreTypes = array_values(array_intersect($schemaTypes, self::CORE_ENTITY_TYPES));
        $hasFaq = in_array('FAQPage', $schemaTypes, true) || in_array('QAPage', $schemaTypes, true);
        $hasBreadcrumb = in_array('BreadcrumbList', $schemaTypes, true);
        $entityRelationships = self::hasAnyProperty($properties, ['provider', 'brand', 'manufacturer', 'offers', 'areaServed', 'address', 'sameAs']);

        $dimensions = [];

        $retrievability = 0;
        if ($status >= 200 && $status < 300) {
            $retrievability += 45;
        }
        if ($indexable) {
            $retrievability += 25;
        }
        if ($canonical !== '') {
            $retrievability += 15;
        }
        if ($title !== '' && $h1Count > 0) {
            $retrievability += 10;
        }
        if ($language !== '') {
            $retrievability += 5;
        }
        $dimensions['retrievability'] = self::bound($retrievability);

        $entityClarity = 10;
        if ($coreTypes !== []) {
            $entityClarity += 30;
        }
        if ($entities !== []) {
            $entityClarity += 25;
        }
        if (self::hasAnyProperty($properties, ['url', 'identifier', 'sameAs'])) {
            $entityClarity += 15;
        }
        if ($entityRelationships) {
            $entityClarity += 15;
        }
        if ($title !== '' && $h1Count === 1) {
            $entityClarity += 5;
        }
        $dimensions['entity_clarity'] = self::bound($entityClarity);

        $answerability = 15;
        $answerability += min(20, (int) floor($wordCount / 50) * 2);
        $answerability += min(18, count($headings) * 3);
        $answerability += min(18, $questionHeadings * 6 + $questionBlocks * 4);
        $answerability += $definitionSignal ? 12 : 0;
        $answerability += min(10, ($lists + $tables) * 3);
        $answerability += $hasFaq ? 12 : 0;
        if ($wordCount < 80) {
            $answerability = min(35, $answerability);
        }
        $dimensions['answerability'] = self::bound($answerability);

        $evidenceTrust = 10;
        $evidenceTrust += $authorSignal ? 20 : 0;
        $evidenceTrust += ($publishedSignal || $modifiedSignal) ? 18 : 0;
        $evidenceTrust += $sourceSignal ? 25 : 0;
        $evidenceTrust += $contactSignal ? 15 : 0;
        $evidenceTrust += self::hasAnyProperty($properties, ['publisher', 'reviewedBy', 'citation', 'isBasedOn']) ? 12 : 0;
        $dimensions['evidence_trust'] = self::bound($evidenceTrust);

        $citationReadiness = 10;
        $citationReadiness += $canonical !== '' ? 20 : 0;
        $citationReadiness += ($title !== '' && $h1Count === 1) ? 18 : 0;
        $citationReadiness += min(16, $headingAnchors * 4);
        $citationReadiness += min(16, count($headings) * 3);
        $citationReadiness += $externalSourceLinks > 0 ? 15 : 0;
        $citationReadiness += $semanticContainers > 0 ? 5 : 0;
        $dimensions['citation_readiness'] = self::bound($citationReadiness);

        $freshness = 45;
        $freshness += $publishedSignal ? 20 : 0;
        $freshness += $modifiedSignal ? 25 : 0;
        $freshness += self::hasAnyProperty($properties, ['datePublished', 'dateModified']) ? 10 : 0;
        $dimensions['freshness'] = self::bound($freshness);

        $localization = 35;
        $localization += $language !== '' ? 30 : 0;
        $localization += min(20, $hreflangCount * 5);
        $localization += self::hasAnyProperty($properties, ['areaServed', 'address', 'spatialCoverage', 'availableLanguage']) ? 15 : 0;
        $dimensions['localization'] = self::bound($localization);

        $machineReadability = 10;
        $machineReadability += $invalidSchemaCount === 0 && $schemaNodes !== [] ? 25 : 0;
        $machineReadability += $coreTypes !== [] ? 25 : 0;
        $machineReadability += ($hasFaq || $hasBreadcrumb) ? 15 : 0;
        $machineReadability += $semanticContainers > 0 ? 15 : 0;
        if ($images === 0 || $missingAlt === 0) {
            $machineReadability += 10;
        } elseif ($missingAlt < $images) {
            $machineReadability += 5;
        }
        $dimensions['machine_readability'] = self::bound($machineReadability);

        $score = self::weightedScore($dimensions);
        if (!$indexable || $status < 200 || $status >= 400) {
            $score = min(45.0, $score);
        }

        $signals = [
            'http_status' => $status,
            'indexable' => $indexable,
            'canonical_present' => $canonical !== '',
            'language' => $language !== '' ? mb_substr($language, 0, 32) : null,
            'word_count' => $wordCount,
            'heading_count' => count($headings),
            'question_heading_count' => $questionHeadings,
            'question_block_count' => $questionBlocks,
            'answer_block_count' => count($answerBlocks),
            'heading_anchor_count' => $headingAnchors,
            'list_count' => $lists,
            'table_count' => $tables,
            'external_source_links' => $externalSourceLinks,
            'schema_types' => array_slice($schemaTypes, 0, 80),
            'invalid_schema_count' => $invalidSchemaCount,
            'author_signal' => $authorSignal,
            'published_signal' => $publishedSignal,
            'modified_signal' => $modifiedSignal,
            'source_signal' => $sourceSignal,
            'contact_signal' => $contactSignal,
            'definition_signal' => $definitionSignal,
            'hreflang_count' => $hreflangCount,
            'semantic_container_count' => $semanticContainers,
        ];

        return [
            'algorithm_version' => self::VERSION,
            'formula_hash' => self::formulaHash(),
            'score' => $score,
            'dimensions' => $dimensions,
            'signals' => $signals,
            'recommendations' => self::recommendations($dimensions),
            'content_hash' => hash('sha256', mb_strtolower($bodyText)),
            'primary_entities' => $entities,
            'intents' => $intents,
        ];
    }

    /**
     * @param list<array<string,mixed>> $pages
     * @param list<array<string,mixed>> $bots
     * @return array<string,mixed>
     */
    public static function aggregate(array $pages, array $bots = []): array
    {
        $validPages = array_values(array_filter($pages, static fn(array $page): bool => isset($page['score'], $page['dimensions'])));
        if ($validPages === []) {
            return [
                'algorithm_version' => self::VERSION,
                'formula_hash' => self::formulaHash(),
                'score' => null,
                'dimensions' => [],
                'coverage' => ['page_count' => 0],
                'recommendations' => [],
            ];
        }

        $dimensionTotals = array_fill_keys(array_keys(self::WEIGHTS), 0.0);
        $weightTotal = 0.0;
        $hashCounts = [];
        $entityCounts = [];
        $pagesWithEntities = 0;
        $intentCounts = [];
        foreach ($validPages as $page) {
            $pageWeight = self::pageImportance((string) ($page['url'] ?? ''));
            $weightTotal += $pageWeight;
            foreach (self::WEIGHTS as $dimension => $_weight) {
                $dimensionTotals[$dimension] += (float) ($page['dimensions'][$dimension] ?? 0) * $pageWeight;
            }
            $hash = (string) ($page['content_hash'] ?? '');
            if ($hash !== '') {
                $hashCounts[$hash] = ($hashCounts[$hash] ?? 0) + 1;
            }
            $pageEntities = [];
            foreach ((array) ($page['primary_entities'] ?? []) as $entity) {
                $normalized = mb_strtolower(trim((string) $entity));
                if ($normalized !== '') {
                    $pageEntities[$normalized] = true;
                }
            }
            if ($pageEntities !== []) {
                $pagesWithEntities++;
                foreach (array_keys($pageEntities) as $entity) {
                    $entityCounts[$entity] = ($entityCounts[$entity] ?? 0) + 1;
                }
            }
            foreach ((array) ($page['intents'] ?? []) as $intent) {
                $intentCounts[(string) $intent] = ($intentCounts[(string) $intent] ?? 0) + 1;
            }
        }

        $dimensions = [];
        foreach ($dimensionTotals as $dimension => $total) {
            $dimensions[$dimension] = round($total / max(0.01, $weightTotal), 1);
        }

        $relevantBots = array_values(array_filter($bots, static fn(array $bot): bool => in_array((string) ($bot['purpose'] ?? ''), ['search', 'retrieval'], true)));
        $botPolicyAllowRate = null;
        $botHttpProbeRate = null;
        $probedBots = [];
        if ($relevantBots !== []) {
            $policyAllowed = count(array_filter($relevantBots, static fn(array $bot): bool => (bool) ($bot['robots_allowed'] ?? false)));
            $botPolicyAllowRate = round($policyAllowed / count($relevantBots) * 100, 1);
            $probedBots = array_values(array_filter($relevantBots, static fn(array $bot): bool => ($bot['http_status'] ?? null) !== null));
            if ($probedBots !== []) {
                $probePassed = count(array_filter($probedBots, static fn(array $bot): bool => (bool) ($bot['access_allowed'] ?? false)));
                $botHttpProbeRate = round($probePassed / count($probedBots) * 100, 1);
                $dimensions['retrievability'] = round($dimensions['retrievability'] * 0.75 + $botPolicyAllowRate * 0.15 + $botHttpProbeRate * 0.10, 1);
            } else {
                $dimensions['retrievability'] = round($dimensions['retrievability'] * 0.85 + $botPolicyAllowRate * 0.15, 1);
            }
        }

        $duplicatePages = array_sum(array_map(static fn(int $count): int => max(0, $count - 1), $hashCounts));
        arsort($entityCounts);
        $entityConsistency = $pagesWithEntities > 0 ? round(((int) reset($entityCounts)) / $pagesWithEntities * 100, 1) : null;
        if ($entityConsistency !== null && $entityConsistency < 60) {
            $dimensions['entity_clarity'] = max(0, round($dimensions['entity_clarity'] - 8, 1));
        }

        $score = self::weightedScore($dimensions);
        if ($duplicatePages > 0) {
            $score = max(0, round($score - min(10, ($duplicatePages / count($validPages)) * 20), 1));
        }

        $recommendations = self::recommendations($dimensions, count($validPages));
        if ($duplicatePages > 0) {
            $recommendations[] = [
                'dimension' => 'citation_readiness',
                'label' => self::LABELS['citation_readiness'],
                'title' => '合并或区分重复正文',
                'action' => '对重复页面设定唯一搜索意图、规范地址和差异化证据，避免多个 URL 争夺同一主题。',
                'impact' => min(100, 45 + $duplicatePages * 10),
                'effort' => 2,
                'confidence' => 0.95,
                'affected_pages' => $duplicatePages + 1,
                'priority_score' => round((45 + $duplicatePages * 10) * 0.95 / 2, 1),
            ];
        }
        usort($recommendations, static fn(array $a, array $b): int => ($b['priority_score'] <=> $a['priority_score']));

        return [
            'algorithm_version' => self::VERSION,
            'formula_hash' => self::formulaHash(),
            'score' => $score,
            'dimensions' => $dimensions,
            'coverage' => [
                'page_count' => count($validPages),
                'bot_access_rate' => $botHttpProbeRate,
                'bot_policy_allow_rate' => $botPolicyAllowRate,
                'bot_http_probe_rate' => $botHttpProbeRate,
                'bot_http_probe_count' => count($probedBots),
                'duplicate_page_count' => $duplicatePages,
                'entity_consistency' => $entityConsistency,
                'detected_intents' => $intentCounts,
            ],
            'recommendations' => array_slice($recommendations, 0, 12),
        ];
    }

    /** @param array<string,float|int> $dimensions */
    private static function weightedScore(array $dimensions): float
    {
        $score = 0.0;
        foreach (self::WEIGHTS as $dimension => $weight) {
            $score += (float) ($dimensions[$dimension] ?? 0) * $weight;
        }
        return round(self::bound($score), 1);
    }

    /**
     * @param array<string,float|int> $dimensions
     * @return list<array<string,mixed>>
     */
    private static function recommendations(array $dimensions, int $affectedPages = 1): array
    {
        $actions = [
            'retrievability' => ['修复可抓取与规范地址', '确保公开页面返回稳定 2xx、允许目标爬虫访问，并声明唯一 canonical。', 1, 0.99],
            'entity_clarity' => ['统一主体与服务实体', '在可见正文和 JSON-LD 中一致声明主体名称、官网、品牌、服务关系及可核验标识。', 2, 0.92],
            'answerability' => ['增加可直接提取的答案模块', '围绕真实用户问题补充简短定义、步骤、比较、适用边界和 FAQ，避免关键词堆砌。', 2, 0.88],
            'evidence_trust' => ['补齐来源与责任信息', '为关键结论提供真实作者、日期、来源链接、审校或主体联系方式；结构化数据必须与页面可见内容一致。', 2, 0.94],
            'citation_readiness' => ['强化稳定引用单元', '使用唯一标题、清晰小节、稳定锚点、规范 URL 和出处链接，使答案片段可定位、可复核。', 2, 0.90],
            'freshness' => ['声明发布与更新时间', '按页面类型公开真实发布日期和修改日期；无实质更新时不要伪造新日期。', 1, 0.90],
            'localization' => ['明确语言与服务地域', '声明页面语言；仅在确有对应内容时增加 hreflang、地址、areaServed 或 spatialCoverage。', 2, 0.86],
            'machine_readability' => ['修复语义结构与 Schema', '使用有效 JSON-LD 和 main、article、section 等语义结构，类型与属性必须对应可见内容。', 2, 0.93],
        ];
        $rows = [];
        foreach (self::WEIGHTS as $dimension => $weight) {
            $score = (float) ($dimensions[$dimension] ?? 0);
            if ($score >= 78) {
                continue;
            }
            [$title, $action, $effort, $confidence] = $actions[$dimension];
            $impact = round((100 - $score) * $weight * 10, 1);
            $rows[] = [
                'dimension' => $dimension,
                'label' => self::LABELS[$dimension],
                'title' => $title,
                'action' => $action,
                'impact' => min(100, $impact),
                'effort' => $effort,
                'confidence' => $confidence,
                'affected_pages' => $affectedPages,
                'priority_score' => round(min(100, $impact) * $confidence / $effort, 1),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => ($b['priority_score'] <=> $a['priority_score']));
        return $rows;
    }

    /** @return array{0:list<string>,1:list<array<string,mixed>>,2:int} */
    private static function schemaData(DOMXPath $xpath): array
    {
        $types = [];
        $nodes = [];
        $invalid = 0;
        $scripts = $xpath->query('//script[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]');
        $seen = 0;
        foreach ($scripts ?: [] as $script) {
            if (++$seen > 32) {
                break;
            }
            $json = trim((string) $script->textContent);
            if ($json === '' || strlen($json) > 262144) {
                $invalid++;
                continue;
            }
            try {
                $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $invalid++;
                continue;
            }
            self::collectSchemaNodes($decoded, $nodes, 200);
        }
        foreach ($nodes as $node) {
            foreach ((array) ($node['@type'] ?? []) as $type) {
                if (is_string($type) && $type !== '') {
                    $types[] = mb_substr($type, 0, 120);
                }
            }
        }
        return [array_values(array_unique($types)), $nodes, $invalid];
    }

    /** @param mixed $value @param list<array<string,mixed>> $nodes */
    private static function collectSchemaNodes(mixed $value, array &$nodes, int $limit): void
    {
        if (count($nodes) >= $limit || !is_array($value)) {
            return;
        }
        if (array_key_exists('@type', $value)) {
            $nodes[] = $value;
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                self::collectSchemaNodes($child, $nodes, $limit);
            }
            if (count($nodes) >= $limit) {
                break;
            }
        }
    }

    /** @param list<array<string,mixed>> $nodes @return array<string,bool> */
    private static function schemaProperties(array $nodes): array
    {
        $properties = [];
        foreach ($nodes as $node) {
            foreach (array_keys($node) as $key) {
                if (is_string($key) && $key !== '' && $key[0] !== '@') {
                    $properties[$key] = true;
                }
            }
        }
        return $properties;
    }

    /** @param list<array<string,mixed>> $nodes @return list<string> */
    private static function primaryEntities(array $nodes): array
    {
        $entities = [];
        $siteEntityTypes = ['Organization', 'LocalBusiness', 'Corporation', 'Person', 'Brand'];
        foreach ($nodes as $node) {
            $types = (array) ($node['@type'] ?? []);
            if (array_intersect($types, $siteEntityTypes) === []) {
                continue;
            }
            $name = $node['name'] ?? $node['headline'] ?? null;
            if (is_string($name) && trim($name) !== '') {
                $entities[] = mb_substr(self::cleanText($name), 0, 180);
            }
        }
        return array_slice(array_values(array_unique($entities)), 0, 30);
    }

    /** @param array<string,bool> $properties @param list<string> $needles */
    private static function hasAnyProperty(array $properties, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (isset($properties[$needle])) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function nodeTexts(DOMXPath $xpath, string $expression, int $limit): array
    {
        $texts = [];
        foreach ($xpath->query($expression) ?: [] as $node) {
            $text = self::cleanText((string) $node->textContent);
            if ($text !== '') {
                $texts[] = mb_substr($text, 0, 240);
            }
            if (count($texts) >= $limit) {
                break;
            }
        }
        return $texts;
    }

    private static function externalSourceLinkCount(DOMXPath $xpath, string $pageUrl): int
    {
        $pageHost = strtolower((string) parse_url($pageUrl, PHP_URL_HOST));
        $count = 0;
        foreach ($xpath->query('//main//a[@href]|//article//a[@href]') ?: [] as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }
            $href = trim($link->getAttribute('href'));
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            if ($host !== '' && $host !== $pageHost) {
                $count++;
            }
            if ($count >= 100) {
                break;
            }
        }
        return $count;
    }

    /** @param list<string> $schemaTypes @param list<string> $headings @return list<string> */
    private static function detectIntents(string $url, array $schemaTypes, array $headings, string $bodyText): array
    {
        $haystack = mb_strtolower($url . ' ' . implode(' ', $headings) . ' ' . mb_substr($bodyText, 0, 8000));
        $rules = [
            'home' => '~/(?:$|index\.)~u',
            'about' => '/(关于|公司简介|团队|about|company)/iu',
            'service' => '/(服务|方案|咨询|设计|service|solution)/iu',
            'product' => '/(产品|价格|套餐|product|pricing)/iu',
            'case' => '/(案例|客户故事|成果|case|portfolio)/iu',
            'article' => '/(资讯|文章|观点|指南|news|blog|guide)/iu',
            'faq' => '/(常见问题|问答|faq|questions?)/iu',
            'contact' => '/(联系|地址|电话|contact)/iu',
            'local' => '/(服务地区|覆盖城市|所在地区|area served|location)/iu',
        ];
        $intents = [];
        foreach ($rules as $intent => $pattern) {
            if (preg_match($pattern, $haystack) === 1) {
                $intents[] = $intent;
            }
        }
        if (array_intersect($schemaTypes, ['Article', 'NewsArticle', 'BlogPosting']) !== []) {
            $intents[] = 'article';
        }
        if (array_intersect($schemaTypes, ['Service']) !== []) {
            $intents[] = 'service';
        }
        if (array_intersect($schemaTypes, ['Product']) !== []) {
            $intents[] = 'product';
        }
        if (array_intersect($schemaTypes, ['FAQPage', 'QAPage']) !== []) {
            $intents[] = 'faq';
        }
        return array_values(array_unique($intents));
    }

    private static function looksLikeQuestion(string $text): bool
    {
        return self::matches('/[?？]$/u', $text)
            || self::matches('/^(什么|为什么|如何|怎么|是否|哪里|哪种|多少|谁|when\b|what\b|why\b|how\b|where\b|which\b)/iu', $text);
    }

    private static function pageImportance(string $url): float
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        if ($path === '' || preg_match('/^index\.[a-z0-9]+$/i', $path) === 1) {
            return 1.5;
        }
        return substr_count($path, '/') === 0 ? 1.2 : 1.0;
    }

    private static function cleanText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function matches(string $pattern, string $value): bool
    {
        return preg_match($pattern, $value) === 1;
    }

    private static function bound(float|int $value): float
    {
        return round(max(0, min(100, (float) $value)), 1);
    }
}
