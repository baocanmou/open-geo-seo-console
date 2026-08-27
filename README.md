# Open GEO SEO Console

Open GEO SEO Console is a self-hosted control plane for technical SEO, search-engine evidence, crawler accessibility, and generative-engine optimization (GEO). It keeps observed facts separate from claims: a successful crawl is not an index, a submitted URL is not a ranking, and a model response is not a durable recommendation.

The repository ships with reserved example domains only. It contains no production credentials, customer inventory, private production paths, audit exports, or deployment history. Conventional `/opt`, `/etc`, `/run`, loopback, service-user, and TLS paths in the self-hosting templates are public placeholders and are not copied from a live deployment.

## What it does

- Audits HTML, metadata, canonical URLs, headings, structured data, robots.txt, sitemaps, `llms.txt`, response status, and response time.
- Checks 22 search, retrieval, training, and extended-use crawler controls during every site audit.
- Catalogs 36 search, AI, analytics, and submission integrations with explicit `public`, `official`, or `manual` evidence modes.
- Stores search snapshots, AI answer evidence, findings, tasks, audit runs, and privacy-preserving activity records.
- Protects the console with Argon2id-compatible password hashing, stateless pre-login CSRF, same-origin checks, nonblocking per-account verification serialization, per-IP and account-plus-IP failure limits, strict cookies, idle and absolute session expiry, session-ID rotation, password-change revocation, and browser binding.
- Lets an authenticated administrator change the password from Account & Security after re-entering the current password; other sessions are revoked and the active session receives a new ID and CSRF token.
- Bounds every audit by elapsed time, total requests, total response bytes, per-document size, and a singleton worker lease.
- Encrypts integration configuration at rest with libsodium when configuration is supplied by an authorized deployment.

## Evidence boundaries

Public crawler probes establish only whether the configured robots policy and a controlled HTTP request allow access at that moment. Search position, index coverage, and AI citation or recommendation require official API data or traceable captured evidence. The project intentionally does not scrape search-result pages or invent unavailable metrics.

## Requirements

- PHP 8.2+ with `curl`, `dom`, `mbstring`, `pdo_mysql`, and `sodium`
- MySQL 8.0+
- Node.js 22+ and npm
- Chromium and the pinned open-source Lighthouse 12.8.2 CLI for optional local lab audits
- Nginx or an equivalent HTTPS reverse proxy

## Quick start

```bash
cp backend/.env.example backend/.env
cd frontend
npm ci
npm run build
cd ..
```

Set a unique database password and generate a random `APP_KEY` before starting. Production mode refuses plain HTTP, placeholder application keys, or insecure session cookies.

Create the schema and the initial administrator. The default username is `bcm`; provide the password only through the process environment.

```bash
php backend/cli.php migrate
php backend/cli.php seed
read -rs ADMIN_PASSWORD
export ADMIN_PASSWORD
php backend/cli.php create-admin bcm
unset ADMIN_PASSWORD
```

Use a unique random value of at least 16 characters. See [Deployment](docs/DEPLOYMENT.md), [Architecture](docs/ARCHITECTURE.md), and [Security Policy](SECURITY.md).
The self-service password policy defaults to 16 characters through `PASSWORD_MIN_LENGTH`; deployments may set a bounded value from 8 to 128 without changing source code.

## Scheduled collection

Run the scheduler and normal site-audit worker as the web-service account. Run only `public-worker` as root so it can switch to the isolated `open-geo-lighthouse` account. A sample is in `deploy/open-geo.cron`. Site audits collect technical signals; Common Crawl and local Lighthouse evidence are stored separately. Integrations that require an account, OAuth grant, or API token remain visibly unconfigured until an operator supplies valid authorization on their own server.

## Development

```bash
cd frontend
npm ci
npm run lint
npm run build
php ../backend/tests/run.php
```

## 中文说明

本项目用于统一管理多站点技术 SEO、国内外搜索引擎证据、AI 爬虫可访问性和 GEO 推荐证据。开源仓库只包含保留示例域名与配置占位符；生产账号、客户网站、服务器地址、数据库资料和内部审计材料均不在仓库内。

## License

[MIT](LICENSE)
