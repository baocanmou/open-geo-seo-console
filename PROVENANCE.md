# Provenance record

## Implementation origin

Open GEO SEO Console is an independently implemented BCM reference project.
Its PHP services, React interface, database migrations, tests, and operational
examples were written for this repository. No production database, customer
inventory, credential, browser session, private server path, or historical
audit export is a source input for the public package.

AI coding tools may assist implementation, but every released change requires
human requirement selection, code review, tests, and release approval. Chat
transcripts are not accepted as the only proof of authorship or approval.

## Conceptual research boundary

The following public projects were reviewed only to understand the product
landscape:

- `yaojingang/GEOHub`, fixed audit commit
  `2210f7f22153cfdf721905c2ac86318db97401b1`;
- `zubair-trabzada/geo-seo-claude`, fixed audit commit
  `a58098a839e2c97df7ae89191b3021fa9e0f88c3`.

Their source code, prompt text, scoring formulas, documentation expression,
visual assets, and sample customer material were not imported. BCM's distinct
implementation centers on evidence-state separation, official-result
readback, null and negative observations, multi-site intent ownership, and a
controlled production/retest loop.

On 2026-09-01 a read-only comparison covered the current public-console
candidate against both fixed commits. It found zero identical files and zero
matching seven-line sequences after removing comments, blank lines, short
lines, and whitespace differences. Dependency, build, release, output, and
Git metadata directories were excluded. This is engineering provenance
evidence, not a legal infringement opinion or a substitute for forensic expert
analysis.

## Third-party dependency origin

Frontend dependencies are resolved from `frontend/package-lock.json` and are
not copied into the repository. Their names, versions, and declared licenses
are summarized in `THIRD_PARTY_NOTICES.md`. The PHP first-party code has no
Composer dependency manifest.

## Release proof

Every public release must retain:

1. the clean Git commit used for the build;
2. the lock-file hash and release archive SHA-256;
3. successful security, origin, license, lint, build, and PHP test checks;
4. a human-reviewed file diff;
5. public readback of the final GitHub archive.
