# Security policy

## Supported versions

Security fixes are applied to the latest release on the `main` branch.

## Reporting a vulnerability

Use GitHub private vulnerability reporting when it is available for this repository. Do not place credentials, production URLs, customer domains, server addresses, exploit payloads against live systems, or other sensitive evidence in a public issue.

Include the affected commit, reproduction steps against a local or disposable environment, impact, and a proposed mitigation if known. Maintainers will acknowledge a complete report as soon as practical.

## Deployment responsibilities

- Keep `.env` outside version control and readable only by the service account.
- Use a dedicated least-privilege database user and a unique application key.
- Terminate TLS at a maintained reverse proxy and keep `SESSION_SECURE=true`.
- Keep the default 16-character password policy when practical; changing a password must require the current password, same-origin CSRF validation, throttling, session rotation, and revocation of other sessions.
- Do not expose MySQL, PHP-FPM, storage, migrations, seeds, or CLI files publicly.
- Grant third-party OAuth scopes and API tokens only when a collector needs them.
- Do not enable local Lighthouse without the dedicated account, fixed-port firewall template, live rule verification, Chromium sandbox, serial lock, and root-owned readiness marker.
- Back up and test restore procedures before schema or application upgrades.
- For an Internet-facing admin panel, add provider-level bot protection and prefer MFA or SSO. The built-in account/IP throttles reduce common bursts but are not a complete defense against distributed credential stuffing.

The project does not promise ranking, indexing, or AI recommendation outcomes.

## Public release boundary

Public releases may include conventional example paths and topology required to operate a generic self-hosted installation. They must not include paths, addresses, accounts, certificates, credentials, inventories, logs, screenshots, or configuration copied from a production or customer environment. The exact staged tree and fresh clone are scanned before release.
