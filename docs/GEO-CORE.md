# BCM-GEO Core

BCM-GEO Core is the console's deterministic, account-free analysis layer. It measures whether a public page is easy to retrieve, understand, verify, extract, and cite. It does not claim that a search engine indexed the page or that an AI product mentioned, cited, or recommended it.

## Version 2.2.0 dimensions

| Dimension | Weight | Observable evidence |
| --- | ---: | --- |
| Retrievability | 15% | HTTP state, indexability, canonical, title/H1, language |
| Entity clarity | 15% | Valid entity types, named entities, identifiers, relationships |
| Answerability | 18% | Useful sections, question headings, definitions, lists, tables, FAQ |
| Evidence and trust | 15% | Authors, dates, sources, publisher/reviewer, contact evidence |
| Citation readiness | 15% | Canonical URL, unique heading structure, anchors, source links, semantic containers |
| Freshness transparency | 10% | Truthful publication and modification dates in visible and structured content |
| Localization | 5% | Language, hreflang, real geographic coverage or address properties |
| Machine readability | 7% | Valid JSON-LD, matching entity types, FAQ/breadcrumbs, semantic HTML, image alternatives |

The page score is the weighted sum of the eight dimensions. Non-indexable or failed pages are capped at 45. Short pages cannot obtain a high answerability score merely by repeating a keyword. No keyword-density, word-count-only, `llms.txt`, submission, or crawler-name bonus exists.

The site score uses page-importance weighting, blends the search/retrieval robots-policy allow rate into retrievability, measures dominant site-entity consistency, and applies a bounded exact-duplicate penalty. If explicitly enabled, a small controlled-HTTP component is added separately. It records detected intent groups without assuming that every site needs every page type.

Version 2.2.0 deliberately stops issuing a burst of fake bot User-Agent requests. Sending a crawler name from an unrelated server cannot prove how a verified Google, Baidu, Bing, OpenAI, Anthropic, or Perplexity crawler is treated and can itself trigger WAF rate limits. Every audit still parses all supported robots groups. Optional controlled probes are disabled by default, limited to four configured names, stored with an explicit evidence scope, and never described as official bot traffic. Normal page requests are paced by `CRAWL_MIN_INTERVAL_MS`.

Each run keeps the homepage in the sample and rotates a bounded set of additional URLs selected from the sitemap or discovered internal links. `AUDIT_PAGE_BATCH` defaults to four and is capped at eight. Site-level GEO scores aggregate the latest distinct-page evidence from the current algorithm version and formula fingerprint within `GEO_ROLLING_WINDOW_DAYS`, which defaults to 30 days and is capped at 90. This prevents evidence produced by different formulas from being mixed even if a deployment violates version discipline. It also makes coverage grow across scheduled runs without turning the console into an aggressive crawler or allowing a single WAF response to erase previously verified evidence. The snapshot records attempted, successful, discovered, rolling-page, and window counts separately.

The normal worker also enforces `AUDIT_GLOBAL_MIN_INTERVAL_SECONDS` across sites. The default 45-second cooldown protects shared CDN/WAF infrastructure even if an operator repeatedly invokes the worker; queued jobs stay pending until a later invocation rather than bypassing the limit.

## Priority model

Recommendations are ranked from the observed score gap, dimension weight, affected page count, confidence, and estimated effort. The result is an explainable work queue, not an automatic content-generation instruction. High-impact changes still require editorial review because structured data and evidence must match visible facts.

## Stored evidence

Each audit writes:

- algorithm and formula versions;
- page and site scores by dimension;
- bounded signal counts and booleans;
- normalized content hashes for duplicate checks;
- named site entities and detected intent groups;
- prioritized recommendations and coverage statistics.
- robots-policy coverage separately from optional controlled HTTP probe coverage.

Raw page body content is not copied into the GEO signal tables. Provider credentials and private production configuration are never part of the formula or evidence payload.

## Extension boundary

Official search-console, analytics, and model evidence should be stored in their dedicated tables and presented beside this core. A connector may improve confidence or confirm an external state, but must not rewrite historical public-page evidence or turn an unavailable observation into a positive score.
