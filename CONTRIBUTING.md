# Contributing

Issues, reproducible test cases, accessibility feedback, and design discussion
are welcome. Unsolicited external source-code pull requests are not merged
until the project publishes a legally reviewed contribution agreement and an
explicit acceptance process. This keeps the copyright chain and outbound
license unambiguous.

1. Open an issue before preparing implementation code.
2. Do not add copied code, prompts, formulas, visual assets, real domains,
   credentials, response exports, screenshots from private consoles, or
   infrastructure identifiers.
3. Identify every external source, dependency, license, and employer or client
   restriction that could affect the proposed work.
4. Run `bash scripts/check-public-release.sh`, `npm ci`, `npm run lint`, and
   `npm run build` in `frontend`, plus `php backend/tests/run.php` when PHP is
   available.
5. Explain evidence boundaries for new collectors. Public crawlability must not
   be presented as indexing, ranking, citation, or recommendation.
6. Add or update tests for security-sensitive parsing, network validation, and
   scoring changes.

Sending code without an accepted contribution agreement does not transfer
copyright or create an obligation for the maintainers to review or merge it.

Security reports belong in private vulnerability reporting, not public issues.
