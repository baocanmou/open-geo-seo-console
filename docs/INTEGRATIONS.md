# Integration and evidence matrix

The seed catalog currently contains 34 integration records across search, AI, analytics, and submission workflows. Twenty-two crawler/control tokens are checked as part of each public site audit.

## Automatic public collection

The auditor evaluates robots policy and, when the token represents a real fetcher, performs a bounded homepage request for major search and retrieval crawlers. Extended-use tokens that do not fetch pages are evaluated as robots controls without sending a synthetic HTTP probe.

## Authorized collection

Google Search Console, Bing Webmaster Tools, Baidu Search Resource Platform, Yandex Webmaster, Naver Search Advisor, IndexNow, and model APIs require operator-owned authorization. Credentials must be supplied only on the deployed server and stored encrypted. Each provider's terms, scopes, quotas, and site verification requirements still apply.

## Manual evidence

When a platform provides no suitable official interface, capture the prompt/query, provider and model, timestamp, response excerpt, cited URL, brand mention flag, and a content hash. Manual evidence is not upgraded to `official_api` provenance.

## Interpretation

- `robots allow` + successful probe: controlled access was possible at that time.
- URL submission accepted: a submission event only.
- Official impressions/position: provider-scoped search evidence for a date and property.
- AI response mention/citation: a reproducible answer snapshot, not a permanent recommendation.
