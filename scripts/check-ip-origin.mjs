#!/usr/bin/env node

import { execFileSync } from 'node:child_process'
import { readFileSync, lstatSync, realpathSync } from 'node:fs'
import { extname, relative, resolve, sep } from 'node:path'

const root = resolve(import.meta.dirname, '..')
const failures = []
const required = [
  'LICENSE',
  'NOTICE',
  'OWNERSHIP.md',
  'PROVENANCE.md',
  'ORIGIN.json',
  'THIRD_PARTY_NOTICES.md',
  'TRADEMARKS.md',
]

for (const path of required) {
  try {
    readFileSync(resolve(root, path))
  } catch {
    failures.push(`missing provenance file: ${path}`)
  }
}

const origin = JSON.parse(readFileSync(resolve(root, 'ORIGIN.json'), 'utf8'))
if (origin.project !== 'open-geo-seo-console') failures.push('ORIGIN project mismatch')
if (origin.steward !== '南昌包参谋品牌策划有限公司') failures.push('ORIGIN steward mismatch')
if (origin.first_party_license !== 'MIT') failures.push('ORIGIN license mismatch')
if (!Array.isArray(origin.external_code_imports) || origin.external_code_imports.length !== 0) {
  failures.push('external_code_imports must remain an explicit empty array')
}
for (const reference of origin.conceptual_references || []) {
  if (reference.code_imported !== false || !/^[a-f0-9]{40}$/.test(reference.commit || '')) {
    failures.push(`invalid conceptual reference: ${reference.repository || 'unknown'}`)
  }
}

const candidates = execFileSync('git', [
  '-C', root, 'ls-files', '--cached', '--others', '--exclude-standard',
], { encoding: 'utf8' }).split('\n').filter(Boolean)

const forbiddenNames = /(?:^|\/)(?:\.env|[^/]+\.(?:pem|key|p12|pfx|crt|csr|sql\.gz|tar|tar\.gz|zip|log))$/i
const secretPatterns = [
  /-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/,
  /\bAKIA[0-9A-Z]{16}\b/,
  /\bLTAI[0-9A-Za-z]{12,}\b/,
  /\bAIza[0-9A-Za-z_-]{35}\b/,
  /\bgh[pousr]_[0-9A-Za-z_]{20,}\b/,
  /\bxox[baprs]-[0-9A-Za-z-]{20,}\b/,
  /Authorization:\s*Bearer\s+[0-9A-Za-z._-]{20,}/i,
]
const privatePatterns = [
  /(?:^|[^a-z0-9-])(?:[a-z0-9-]+\.)*bcmsj\.com\b/i,
  /\/www\/(?:wwwroot|backup)\//,
  /\b101\.37\.254\.8\b/,
  /\bgeo_bcmsj_com\b/i,
]
const sourceExtensions = new Set(['.php', '.js', '.jsx', '.mjs', '.sh', '.sql', '.css', '.html'])
const foreignOrigin = /(?:yaojingang|zubair[-_ ]trabzada|geo-seo-claude|copyright\s*(?:\(c\)|©)\s*\d{4}\s+(?!南昌包参谋品牌策划有限公司|BCM\b))/i
let scannedTextFiles = 0

for (const path of candidates) {
  if (forbiddenNames.test(path) || /^(?:output|release|\.playwright-cli)\//.test(path)) {
    failures.push(`forbidden public-release path: ${path}`)
    continue
  }
  const absolute = resolve(root, path)
  const stat = lstatSync(absolute)
  if (stat.isSymbolicLink()) {
    const target = realpathSync(absolute)
    if (target !== root && !target.startsWith(`${root}${sep}`)) {
      failures.push(`symlink escapes repository: ${path}`)
    }
    continue
  }
  if (stat.size > 2_000_000) continue
  let text
  try {
    text = readFileSync(absolute, 'utf8')
  } catch {
    continue
  }
  scannedTextFiles += 1
  const isBoundaryChecker = path === 'scripts/check-ip-origin.mjs' || path === 'scripts/check-public-release.sh'
  if (!isBoundaryChecker && secretPatterns.some((pattern) => pattern.test(text))) failures.push(`possible credential in: ${path}`)
  if (!isBoundaryChecker && privatePatterns.some((pattern) => pattern.test(text))) failures.push(`private deployment detail in: ${path}`)
  if (!isBoundaryChecker && sourceExtensions.has(extname(path)) && foreignOrigin.test(text)) {
    failures.push(`foreign-origin marker in first-party source: ${path}`)
  }
}

const lock = JSON.parse(readFileSync(resolve(root, 'frontend/package-lock.json'), 'utf8'))
const locked = Object.entries(lock.packages || {}).filter(([path]) => path.startsWith('node_modules/'))
const missingLicenses = locked.filter(([, metadata]) => !metadata.license)
if (missingLicenses.length) failures.push(`locked dependencies without declared licenses: ${missingLicenses.length}`)

if (failures.length) {
  for (const failure of failures) console.error(`FAIL: ${failure}`)
  process.exit(1)
}

console.log(`PASS: ${candidates.length} release candidates, ${scannedTextFiles} text files, ${locked.length} locked dependency entries`)
