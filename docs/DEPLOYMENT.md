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

## 5. Scheduler and isolated Lighthouse

Adapt `deploy/open-geo.cron`. Keep its `flock` singleton leases and daily retention cleanup. The normal scheduler and site-audit worker stay on the web-service account. Only `public-worker` runs as root so it can switch to the dedicated `open-geo-lighthouse` account; application code refuses to run Lighthouse from an unprivileged or unisolated worker.

Install Chromium and the pinned open-source Lighthouse CLI, then provision the dedicated account and egress service. Review the template against the host firewall before enabling it.

```bash
sudo npm install --global --ignore-scripts lighthouse@12.8.2
sudo useradd --system --home-dir /var/lib/open-geo-lighthouse --create-home --shell /usr/sbin/nologin open-geo-lighthouse
sudo install -d -o open-geo-lighthouse -g open-geo-lighthouse -m 0700 /var/lib/open-geo-lighthouse /var/lib/open-geo-lighthouse/.config
sudo install -o root -g root -m 0750 deploy/open-geo-lighthouse-egress /usr/local/sbin/open-geo-lighthouse-egress
sudo install -o root -g root -m 0644 deploy/open-geo-lighthouse-egress.service /etc/systemd/system/open-geo-lighthouse-egress.service
sudo systemctl daemon-reload
sudo systemctl enable --now open-geo-lighthouse-egress.service
sudo env -i /usr/local/sbin/open-geo-lighthouse-egress verify
```

The generic firewall template discovers host resolvers from `/etc/resolv.conf`, allows only DNS plus the fixed local Chromium debugging port, rejects private and special-use IPv4 ranges, and rejects other IPv6 egress for that account. It does not alter other users' traffic. If binary paths differ on the distribution, update both the runner constants and deployment template before enabling collection.

Run one manual audit under the dedicated account without `--no-sandbox`. Only after public access works, private and metadata requests fail, and `verify` succeeds should root create `/etc/open-geo/lighthouse-egress-ready`. The application checks both the root-owned marker and live firewall rules before every run. Keep Lighthouse serial and start with `PUBLIC_SYNC_BATCH=1`.

## 6. Verify

- HTTPS redirects and HSTS are correct.
- API responses include no-store and framing protections.
- Login works with `bcm`; a legacy or sample account does not.
- Session idle, absolute, rotation, CSRF, origin, and failed-login controls behave as configured.
- The worker can reach registered public hosts but rejects private or cross-domain targets.
- Backups restore successfully in an isolated environment.
