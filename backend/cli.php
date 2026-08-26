<?php

declare(strict_types=1);

use OpenGeo\Config;
use OpenGeo\Database;
use OpenGeo\SafeHttpClient;
use OpenGeo\Security;
use OpenGeo\SiteAuditor;

require __DIR__ . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$db = Database::connection();
$command = $argv[1] ?? 'help';

try {
    switch ($command) {
        case 'migrate':
            $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(64) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $appliedStmt = $db->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
            $recordStmt = $db->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, NOW())');
            $migrationFiles = glob(__DIR__ . '/migrations/*.sql') ?: [];
            sort($migrationFiles, SORT_STRING);
            foreach ($migrationFiles as $migrationFile) {
                $version = pathinfo($migrationFile, PATHINFO_FILENAME);
                $appliedStmt->execute([$version]);
                if ($appliedStmt->fetchColumn()) {
                    fwrite(STDOUT, "Migration {$version} already applied.\n");
                    continue;
                }
                $sql = file_get_contents($migrationFile);
                if (!is_string($sql) || trim($sql) === '') {
                    throw new RuntimeException("Migration {$version} is empty or unreadable.");
                }
                $db->exec($sql);
                $recordStmt->execute([$version]);
                fwrite(STDOUT, "Migration {$version} applied.\n");
            }
            break;

        case 'seed':
            $catalog = require __DIR__ . '/seeds/catalog.php';
            $siteStmt = $db->prepare("INSERT INTO sites (public_id, name, domain, canonical_url, group_name, status, max_pages, audit_schedule, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 'active', ?, 'daily-staggered', NOW(), NOW())
                ON DUPLICATE KEY UPDATE name = VALUES(name), canonical_url = VALUES(canonical_url), group_name = VALUES(group_name), max_pages = VALUES(max_pages), updated_at = NOW()");
            $siteIdStmt = $db->prepare('SELECT id FROM sites WHERE domain = ?');
            $aliasStmt = $db->prepare('INSERT INTO site_aliases (site_id, hostname, created_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE hostname = VALUES(hostname)');
            foreach ($catalog['sites'] as [$name, $domain, $canonical, $group, $maxPages, $aliases]) {
                $siteStmt->execute([Security::uuid(), $name, $domain, $canonical, $group, $maxPages]);
                $siteIdStmt->execute([$domain]);
                $siteId = (int) $siteIdStmt->fetchColumn();
                foreach ($aliases as $alias) {
                    $aliasStmt->execute([$siteId, $alias]);
                }
            }
            $integrationStmt = $db->prepare("INSERT INTO integrations (integration_key, display_name, category, data_source, access_mode, status, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), category = VALUES(category), data_source = VALUES(data_source), access_mode = VALUES(access_mode), notes = VALUES(notes), updated_at = NOW()");
            foreach ($catalog['integrations'] as $integration) {
                $integrationStmt->execute($integration);
            }
            fwrite(STDOUT, sprintf("Seeded %d sites and %d integrations.\n", count($catalog['sites']), count($catalog['integrations'])));
            break;

        case 'create-admin':
            $username = $argv[2] ?? 'bcm';
            $displayName = $argv[3] ?? '系统管理员';
            if (!preg_match('/\A[a-zA-Z0-9._-]{3,64}\z/', $username)) {
                throw new RuntimeException('Administrator username format is invalid.');
            }
            $password = getenv('ADMIN_PASSWORD');
            if (!is_string($password) || strlen($password) < 16) {
                throw new RuntimeException('ADMIN_PASSWORD must be at least 16 characters.');
            }
            $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
            $hash = password_hash($password, $algorithm);
            $stmt = $db->prepare("INSERT INTO app_users (public_id, username, display_name, password_hash, role, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'admin', 'active', NOW(), NOW())
                ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), password_hash = VALUES(password_hash), status = 'active', session_version = session_version + 1, updated_at = NOW()");
            $stmt->execute([Security::uuid(), $username, $displayName, $hash]);
            fwrite(STDOUT, "Administrator created or rotated.\n");
            break;

        case 'schedule':
            $batch = max(1, min(Config::int('SCHEDULE_BATCH', 1), 3));
            $failureBackoffHours = max(1, min(Config::int('AUDIT_FAILURE_BACKOFF_HOURS', 6), 72));
            $db->beginTransaction();
            $sql = "SELECT s.id, s.public_id FROM sites s
              WHERE s.status = 'active'
                AND (s.last_audit_at IS NULL OR s.last_audit_at < DATE_SUB(NOW(), INTERVAL 23 HOUR))
                AND NOT EXISTS (SELECT 1 FROM audit_runs ar WHERE ar.site_id = s.id AND ar.status IN ('queued','running'))
                AND NOT EXISTS (SELECT 1 FROM audit_runs failed_run WHERE failed_run.site_id = s.id AND failed_run.status = 'failed' AND failed_run.created_at >= DATE_SUB(NOW(), INTERVAL {$failureBackoffHours} HOUR))
              ORDER BY (s.last_audit_at IS NULL) DESC, s.last_audit_at ASC, FIELD(s.group_name,'主要站点','产品站点','客户站点','区域站群'), s.id
              LIMIT {$batch} FOR UPDATE SKIP LOCKED";
            $sites = $db->query($sql)->fetchAll();
            $runStmt = $db->prepare("INSERT INTO audit_runs (public_id, site_id, trigger_type, status, created_at) VALUES (?, ?, 'scheduled', 'queued', NOW())");
            $jobStmt = $db->prepare("INSERT INTO jobs (public_id, job_type, payload, status, available_at, created_at) VALUES (?, 'site_audit', ?, 'queued', NOW(), NOW())");
            foreach ($sites as $site) {
                $auditId = Security::uuid();
                $runStmt->execute([$auditId, $site['id']]);
                $jobStmt->execute([Security::uuid(), json_encode(['audit_id' => $auditId], JSON_UNESCAPED_SLASHES)]);
            }
            $db->commit();
            fwrite(STDOUT, sprintf("Scheduled %d audit(s).\n", count($sites)));
            break;

        case 'worker':
            $db->beginTransaction();
            $job = $db->query("SELECT * FROM jobs WHERE status = 'queued' AND available_at <= NOW() ORDER BY id LIMIT 1 FOR UPDATE")->fetch();
            if (!$job) {
                $db->commit();
                fwrite(STDOUT, "Queue is empty.\n");
                break;
            }
            $db->prepare("UPDATE jobs SET status = 'running', attempts = attempts + 1, started_at = NOW() WHERE id = ?")->execute([$job['id']]);
            $db->commit();
            try {
                $payload = json_decode($job['payload'], true, 16, JSON_THROW_ON_ERROR);
                if ($job['job_type'] !== 'site_audit' || empty($payload['audit_id'])) {
                    throw new RuntimeException('Unsupported or malformed job.');
                }
                (new SiteAuditor($db, new SafeHttpClient()))->run((string) $payload['audit_id']);
                $db->prepare("UPDATE jobs SET status = 'completed', completed_at = NOW(), error_message = NULL WHERE id = ?")->execute([$job['id']]);
                fwrite(STDOUT, "Job completed.\n");
            } catch (Throwable $error) {
                $db->prepare("UPDATE jobs SET status = 'failed', completed_at = NOW(), error_message = ? WHERE id = ?")->execute([mb_substr($error->getMessage(), 0, 500), $job['id']]);
                throw $error;
            }
            break;

        case 'queue-site':
            $domain = strtolower(trim($argv[2] ?? ''));
            $db->beginTransaction();
            $stmt = $db->prepare("SELECT id FROM sites WHERE domain = ? AND status = 'active' LIMIT 1 FOR UPDATE");
            $stmt->execute([$domain]);
            $siteId = (int) $stmt->fetchColumn();
            if ($siteId < 1) {
                throw new RuntimeException('Registered site not found.');
            }
            $existing = $db->prepare("SELECT public_id FROM audit_runs WHERE site_id = ? AND status IN ('queued','running') ORDER BY id DESC LIMIT 1");
            $existing->execute([$siteId]);
            $existingId = $existing->fetchColumn();
            if ($existingId) {
                $db->commit();
                fwrite(STDOUT, "Audit already active: {$existingId}\n");
                break;
            }
            $auditId = Security::uuid();
            $db->prepare("INSERT INTO audit_runs (public_id, site_id, trigger_type, status, created_at) VALUES (?, ?, 'manual', 'queued', NOW())")->execute([$auditId, $siteId]);
            $db->prepare("INSERT INTO jobs (public_id, job_type, payload, status, available_at, created_at) VALUES (?, 'site_audit', ?, 'queued', NOW(), NOW())")
                ->execute([Security::uuid(), json_encode(['audit_id' => $auditId], JSON_UNESCAPED_SLASHES)]);
            $db->commit();
            fwrite(STDOUT, "Audit queued: {$auditId}\n");
            break;

        case 'cleanup':
            $loginRows = $db->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 50000");
            $activityRows = $db->exec("DELETE FROM activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 365 DAY) LIMIT 50000");
            fwrite(STDOUT, sprintf("Cleaned %d login attempt(s) and %d activity row(s).\n", $loginRows, $activityRows));
            break;

        default:
            fwrite(STDOUT, "Open GEO CLI\nCommands: migrate, seed, create-admin, schedule, worker, queue-site <domain>, cleanup\n");
    }
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Error: ' . $error->getMessage() . "\n");
    exit(1);
}
