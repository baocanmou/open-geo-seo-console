# Deployment

## 1. Prepare the host

Install PHP 8.2+, PHP-FPM, MySQL, Nginx, Node.js, and the required PHP extensions. Create a dedicated service account and place the repository at `/opt/open-geo-seo-console` or another explicit path.

## 2. Configure secrets

Copy `backend/.env.example` to `backend/.env`. Replace every placeholder. Use a dedicated least-privilege database user. Generate `APP_KEY` from a cryptographically secure random source and keep `.env` outside backups that are not encrypted.

Production startup fails when `APP_URL` is not HTTPS, the application key is a known placeholder or too short, or secure cookies are disabled.
Unknown `APP_ENV` values also fail startup, and HTTP responses never display raw PHP errors; use protected server logs for diagnostics.

Keep the audit time, request, and byte budgets enabled. Lower them for small hosts; do not raise them until worker, database, and outbound-network limits have been measured.
Keep failed-audit backoff enabled so an unavailable or adversarial registered target cannot be rescheduled every minute.

## 3. Build and initialize

```bash
cd /opt/open-geo-seo-console/frontend
npm ci
npm run build
cd ..
php backend/cli.php migrate
php backend/cli.php seed
read -rs ADMIN_PASSWORD
export ADMIN_PASSWORD
php backend/cli.php create-admin bcm
unset ADMIN_PASSWORD
```

Prefer a protected secret-injection mechanism instead of an inline shell value in production.

## 4. Reverse proxy

Adapt `deploy/nginx.example.conf`, including its literal canonical redirect, unknown-host rejection, API rate limit, body limit, and security headers. Enable a trusted TLS certificate and expose only the frontend build plus `/api/`. Deny dotfiles, backend source, storage, migrations, and environment files.

The application deliberately uses `REMOTE_ADDR` and ignores forwarded client-IP headers. When a CDN or reverse proxy sits in front of Nginx, configure Nginx real-IP handling only for the provider's exact trusted address ranges before requests reach PHP; otherwise keep the direct peer address. Internet-facing deployments should also use provider bot protection and preferably MFA or SSO.

## 5. Scheduler

Adapt `deploy/open-geo.cron`. Keep its `flock` singleton leases and daily retention cleanup. Run the scheduler and worker as the service account. Start with a small batch, observe CPU, network, target rate limits, and queue latency, then tune conservatively.

## 6. Verify

- HTTPS redirects and HSTS are correct.
- API responses include no-store and framing protections.
- Login works with `bcm`; a legacy or sample account does not.
- Session idle, absolute, rotation, CSRF, origin, and failed-login controls behave as configured.
- The worker can reach registered public hosts but rejects private or cross-domain targets.
- Backups restore successfully in an isolated environment.
