# Contributing

Contributions are welcome through focused pull requests.

1. Fork the repository and create a short-lived branch.
2. Do not add real domains, credentials, response exports, screenshots from private consoles, or infrastructure identifiers.
3. Run `npm ci`, `npm run lint`, and `npm run build` in `frontend`, plus `php backend/tests/run.php` when PHP is available.
4. Explain evidence boundaries for new collectors. Public crawlability must not be presented as indexing, ranking, citation, or recommendation.
5. Add or update tests for security-sensitive parsing, network validation, and scoring changes.

Security reports belong in private vulnerability reporting, not public issues.
