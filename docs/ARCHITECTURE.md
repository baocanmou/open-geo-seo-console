# Architecture

The console uses a small React frontend and a PHP API backed by MySQL.

```text
Browser -> HTTPS reverse proxy -> React static files
                              -> PHP API -> MySQL
Scheduler -> jobs table -> web worker -> bounded HTTP auditor -> public sites
                                      -> BCM-GEO Core -> versioned page/site evidence
Root public-worker -> dedicated Lighthouse user -> egress firewall -> Chromium
Official/manual imports -------------------------------> evidence tables
```

## Trust boundaries

- The browser receives no database or integration credentials.
- Mutating API requests require an authenticated strict-cookie session, an exact `Origin`, and a CSRF token.
- The crawler resolves every target and rejects private, reserved, cross-host, credential-bearing, and non-standard-port URLs to reduce SSRF risk.
- Redirects remain inside the registered site and aliases.
- Robots policy is evaluated for every supported crawler name, while optional User-Agent HTTP simulations are disabled by default and reported separately from verified crawler traffic.
- Outbound audit requests use a configurable minimum interval to reduce self-induced WAF throttling.
- The collector keeps a transparent `compatible` User-Agent identity and avoids optional content-encoding negotiation that some WAF profiles reject; response and audit byte limits still apply.
- Integration secrets are encrypted before storage and are never returned by list endpoints.
- Search snapshots, AI answer evidence, Common Crawl observations, local Lighthouse lab results, and derived audit scores remain separate data classes.
- BCM-GEO Core stores formula-hashed page signals and site snapshots; it does not depend on provider credentials or mutate official evidence.
- Local Lighthouse requires a root scheduler only to switch users; Chromium runs as a non-login account whose private-network egress is blocked and dynamically verified.

## Collection modes

- `public`: deterministic public-site or crawler-control checks.
- `official`: authorized provider APIs or verified platform exports.
- `manual`: traceable evidence capture for services without a suitable official API.

Provider access, indexing, ranking, and generative recommendations are separate states. The UI and data model avoid collapsing them into one success flag.
