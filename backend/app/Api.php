<?php

declare(strict_types=1);

namespace OpenGeo;

use PDO;

final class Api
{
    public function __construct(private readonly PDO $db) {}

    public function dispatch(string $method, string $path): never
    {
        if ($method === 'GET' && $path === '/health') {
            $this->db->query('SELECT 1');
            Response::ok(['status' => 'ok', 'version' => Config::get('APP_VERSION', '1.0.0'), 'time' => date(DATE_ATOM)]);
        }
        if ($method === 'GET' && $path === '/auth/me') {
            if (!Security::hasSessionCookie()) {
                $token = Security::bootstrapCsrfToken();
                Response::ok(['user' => null, 'csrf_token' => $token]);
            }
            Security::startSession();
            $user = Auth::user();
            Response::ok(['user' => $user ? $this->publicUser($user) : null, 'csrf_token' => Security::csrfToken()]);
        }
        if ($method === 'POST' && $path === '/auth/login') {
            Security::verifyUnsafeRequest(true);
            $body = Security::jsonBody();
            $user = Auth::login((string) ($body['username'] ?? ''), (string) ($body['password'] ?? ''));
            Response::ok(['user' => $user, 'csrf_token' => Security::csrfToken()]);
        }
        if ($method === 'POST' && $path === '/auth/logout') {
            Security::startSession();
            Security::verifyUnsafeRequest();
            $user = Auth::user();
            if ($user !== null) {
                Activity::record('auth.logout', 'user', (string) $user['public_id']);
            }
            Security::destroySession();
            Response::ok(['logged_out' => true]);
        }

        Security::startSession();
        $user = Auth::requireUser();
        Auth::requireRole($user, ['admin']);
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            Security::verifyUnsafeRequest();
        }
        if ($method === 'GET' && $path === '/dashboard') {
            Response::ok($this->dashboard());
        }
        if ($method === 'GET' && $path === '/sites') {
            Response::ok($this->siteRows());
        }
        if ($method === 'GET' && preg_match('~^/sites/([0-9a-f-]{36})$~', $path, $match)) {
            Response::ok($this->siteDetail($match[1]));
        }
        if ($method === 'POST' && preg_match('~^/sites/([0-9a-f-]{36})/audit$~', $path, $match)) {
            Response::ok($this->queueAudit($match[1], (int) $user['id']));
        }
        if ($method === 'GET' && $path === '/tasks') {
            Response::ok($this->tasks());
        }
        if ($method === 'PATCH' && preg_match('~^/tasks/([0-9a-f-]{36})$~', $path, $match)) {
            Response::ok($this->updateTask($match[1], Security::jsonBody()));
        }
        if ($method === 'GET' && $path === '/integrations') {
            Response::ok($this->integrations());
        }
        if ($method === 'GET' && $path === '/evidence') {
            Response::ok($this->evidence());
        }
        throw new HttpError(404, '接口不存在');
    }

    private function dashboard(): array
    {
        $sites = $this->siteRows();
        $summary = $this->db->query("SELECT
            COUNT(*) AS site_count,
            SUM(last_audit_at IS NOT NULL) AS audited_count,
            ROUND(AVG(technical_score), 2) AS technical_average,
            ROUND(AVG(geo_score), 2) AS geo_average,
            (SELECT COUNT(*) FROM integrations WHERE category = 'search' AND status = 'authorized') AS search_authorized,
            (SELECT COUNT(*) FROM ai_evidence) AS ai_evidence_count,
            (SELECT COUNT(*) FROM provider_observations WHERE status IN ('success','empty')) AS public_observation_count,
            (SELECT COUNT(DISTINCT site_id) FROM provider_observations WHERE status IN ('success','empty')) AS public_observed_sites,
            (SELECT COUNT(*) FROM tasks WHERE status <> 'resolved' AND priority IN ('critical','high')) AS high_priority_tasks,
            MAX(last_audit_at) AS last_collected_at
          FROM sites WHERE status = 'active'")->fetch();
        $recent = $this->db->query("SELECT f.public_id AS id, s.public_id AS site_id, s.name AS site_name, f.category, f.severity, f.title, f.evidence, f.detected_at
          FROM findings f
          JOIN sites s ON s.id = f.site_id
          JOIN audit_runs ar ON ar.id = f.audit_run_id
          JOIN (SELECT site_id, MAX(id) AS audit_run_id FROM audit_runs WHERE status = 'completed' GROUP BY site_id) latest ON latest.site_id = ar.site_id AND latest.audit_run_id = ar.id
          WHERE f.status = 'open'
          ORDER BY f.detected_at DESC, f.id DESC LIMIT 40")->fetchAll();
        $trend = $this->db->query("SELECT engine, snapshot_date, ROUND(AVG(visibility_score), 3) AS visibility_score
          FROM search_snapshots WHERE snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND visibility_score IS NOT NULL
          GROUP BY engine, snapshot_date ORDER BY snapshot_date, engine")->fetchAll();
        return [
            'summary' => $summary,
            'sites' => $sites,
            'tasks' => array_slice(array_values(array_filter($this->tasks(), static fn(array $task): bool => $task['status'] !== 'resolved')), 0, 8),
            'integrations' => $this->integrations(),
            'ai_matrix' => $this->aiMatrix(),
            'trend' => $trend,
            'recent_findings' => $recent,
        ];
    }

    private function siteRows(): array
    {
        $authorizedSearch = (int) $this->db->query("SELECT COUNT(*) FROM integrations WHERE category = 'search' AND status = 'authorized'")->fetchColumn() > 0;
        $sql = "SELECT s.public_id AS id, s.name, s.domain, s.group_name, s.status, s.technical_score, s.geo_score, s.search_score, s.ai_score, s.last_audit_at, s.canonical_url,
          COALESCE((SELECT ar.status FROM audit_runs ar WHERE ar.site_id = s.id ORDER BY ar.created_at DESC, ar.id DESC LIMIT 1), 'pending') AS audit_status,
          (SELECT ar.pages_audited FROM audit_runs ar WHERE ar.site_id = s.id ORDER BY ar.created_at DESC, ar.id DESC LIMIT 1) AS pages_audited,
          (SELECT ar.pages_discovered FROM audit_runs ar WHERE ar.site_id = s.id ORDER BY ar.created_at DESC, ar.id DESC LIMIT 1) AS pages_discovered,
          (SELECT COUNT(*) FROM ai_evidence ae WHERE ae.site_id = s.id) AS ai_evidence_count,
          (SELECT COUNT(*) FROM tasks t WHERE t.site_id = s.id AND t.status <> 'resolved' AND t.priority IN ('critical','high')) AS high_priority_tasks
          FROM sites s WHERE s.status <> 'archived'
          ORDER BY FIELD(s.group_name, '主要站点','产品站点','客户站点','区域站群'), s.id";
        $rows = $this->db->query($sql)->fetchAll();
        foreach ($rows as &$row) {
            $row['search_status'] = $authorizedSearch ? 'authorized' : 'unconfigured';
        }
        return $rows;
    }

    private function siteDetail(string $publicId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM sites WHERE public_id = ? AND status <> \'archived\' LIMIT 1');
        $stmt->execute([$publicId]);
        $site = $stmt->fetch();
        if (!$site) {
            throw new HttpError(404, '站点不存在');
        }
        $runStmt = $this->db->prepare('SELECT public_id AS id, status, pages_discovered, pages_audited, technical_score, geo_score, started_at, completed_at, created_at FROM audit_runs WHERE site_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
        $runStmt->execute([$site['id']]);
        $run = $runStmt->fetch() ?: null;
        $findings = [];
        $bots = [];
        if ($run !== null) {
            $findStmt = $this->db->prepare('SELECT public_id AS id, category, severity, title, evidence, recommendation, affected_url, affected_count, status, detected_at FROM findings WHERE audit_run_id = (SELECT id FROM audit_runs WHERE public_id = ?) ORDER BY FIELD(severity, \'critical\',\'high\',\'medium\',\'low\',\'info\'), id');
            $findStmt->execute([$run['id']]);
            $findings = $findStmt->fetchAll();
            $botStmt = $this->db->prepare('SELECT bot_name, purpose, purpose_label, robots_allowed, http_status, access_allowed, evidence, checked_at FROM bot_checks WHERE audit_run_id = (SELECT id FROM audit_runs WHERE public_id = ?) ORDER BY FIELD(purpose, \'search\',\'retrieval\',\'training\',\'extended\'), bot_name');
            $botStmt->execute([$run['id']]);
            $bots = $botStmt->fetchAll();
        }
        $taskStmt = $this->db->prepare("SELECT t.public_id AS id, t.title, t.description, t.category, t.priority, t.status, t.assignee, t.created_at FROM tasks t WHERE t.site_id = ? ORDER BY FIELD(t.status,'open','in_progress','resolved'), FIELD(t.priority,'critical','high','medium','low'), t.created_at DESC LIMIT 60");
        $taskStmt->execute([$site['id']]);
        $evidenceStmt = $this->db->prepare('SELECT public_id AS id, provider_key, provider_name, prompt, brand_mentioned, cited_url, source_type, captured_at FROM ai_evidence WHERE site_id = ? ORDER BY captured_at DESC LIMIT 40');
        $evidenceStmt->execute([$site['id']]);
        $observationStmt = $this->db->prepare('SELECT po.public_id AS id, po.integration_key, i.display_name, po.snapshot_date, po.status, po.summary_json, po.source_reference, po.evidence_hash, po.observed_at FROM provider_observations po JOIN integrations i ON i.integration_key = po.integration_key WHERE po.site_id = ? ORDER BY po.observed_at DESC LIMIT 40');
        $observationStmt->execute([$site['id']]);
        $observations = $observationStmt->fetchAll();
        foreach ($observations as &$observation) {
            $decoded = json_decode((string) $observation['summary_json'], true, 32);
            $observation['summary'] = is_array($decoded) ? $decoded : [];
            unset($observation['summary_json']);
        }
        unset($observation);
        $platformStmt = $this->db->prepare("SELECT sps.integration_key, i.display_name, sps.property_uri, sps.state, sps.verification_method, sps.evidence_reference, sps.verified_at, sps.last_sync_at, sps.notes FROM site_platform_status sps JOIN integrations i ON i.integration_key = sps.integration_key WHERE sps.site_id = ? ORDER BY FIELD(i.category, 'search','ai','analytics'), i.id");
        $platformStmt->execute([$site['id']]);
        $officialAuthorized = (int) $this->db->query("SELECT COUNT(*) FROM integrations WHERE data_source = 'official' AND status = 'authorized'")->fetchColumn() > 0;
        return [
            'site' => [
                'id' => $site['public_id'], 'name' => $site['name'], 'domain' => $site['domain'], 'canonical_url' => $site['canonical_url'], 'group_name' => $site['group_name'], 'status' => $site['status'], 'max_pages' => (int) $site['max_pages'], 'audit_schedule' => $site['audit_schedule'],
                'technical_score' => $site['technical_score'], 'geo_score' => $site['geo_score'], 'search_score' => $site['search_score'], 'ai_score' => $site['ai_score'], 'last_audit_at' => $site['last_audit_at'],
            ],
            'latest_run' => $run,
            'findings' => $findings,
            'bot_checks' => $bots,
            'integrations' => $this->integrations(),
            'evidence' => $evidenceStmt->fetchAll(),
            'public_observations' => $observations,
            'platform_statuses' => $platformStmt->fetchAll(),
            'tasks' => $taskStmt->fetchAll(),
            'official_authorized' => $officialAuthorized,
        ];
    }

    private function queueAudit(string $sitePublicId, int $userId): array
    {
        $runId = Security::uuid();
        $jobId = Security::uuid();
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT id, public_id, name FROM sites WHERE public_id = ? AND status = \'active\' LIMIT 1 FOR UPDATE');
            $stmt->execute([$sitePublicId]);
            $site = $stmt->fetch();
            if (!$site) {
                throw new HttpError(404, '站点不存在或已暂停');
            }
            $existing = $this->db->prepare("SELECT public_id FROM audit_runs WHERE site_id = ? AND status IN ('queued','running') ORDER BY id DESC LIMIT 1");
            $existing->execute([$site['id']]);
            $existingId = $existing->fetchColumn();
            if ($existingId) {
                $this->db->commit();
                return ['id' => $existingId, 'message' => '该站点已有采集任务，系统不会重复入队'];
            }
            $this->db->prepare("INSERT INTO audit_runs (public_id, site_id, trigger_type, status, created_at) VALUES (?, ?, 'manual', 'queued', NOW())")->execute([$runId, $site['id']]);
            $this->db->prepare("INSERT INTO jobs (public_id, job_type, payload, status, available_at, created_at) VALUES (?, 'site_audit', ?, 'queued', NOW(), NOW())")
                ->execute([$jobId, json_encode(['audit_id' => $runId, 'requested_by' => $userId], JSON_UNESCAPED_SLASHES)]);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        Activity::record('audit.queued', 'site', $sitePublicId, ['audit_id' => $runId]);
        return ['id' => $runId, 'message' => '采集任务已进入受控队列'];
    }

    private function tasks(): array
    {
        return $this->db->query("SELECT t.public_id AS id, s.public_id AS site_id, s.name AS site_name, t.title, t.description, t.category, t.priority, t.status, t.assignee, t.created_at, t.updated_at, t.resolved_at
          FROM tasks t LEFT JOIN sites s ON s.id = t.site_id
          ORDER BY FIELD(t.status,'open','in_progress','resolved'), FIELD(t.priority,'critical','high','medium','low'), t.created_at DESC LIMIT 300")->fetchAll();
    }

    private function updateTask(string $publicId, array $body): array
    {
        $status = (string) ($body['status'] ?? '');
        if (!in_array($status, ['open', 'in_progress', 'resolved'], true)) {
            throw new HttpError(422, '任务状态无效');
        }
        $stmt = $this->db->prepare("UPDATE tasks SET status = ?, resolved_at = IF(? = 'resolved', NOW(), NULL), updated_at = NOW() WHERE public_id = ?");
        $stmt->execute([$status, $status, $publicId]);
        if ($stmt->rowCount() < 1) {
            $exists = $this->db->prepare('SELECT id FROM tasks WHERE public_id = ?');
            $exists->execute([$publicId]);
            if (!$exists->fetchColumn()) {
                throw new HttpError(404, '任务不存在');
            }
        }
        Activity::record('task.updated', 'task', $publicId, ['status' => $status]);
        return ['id' => $publicId, 'status' => $status];
    }

    private function integrations(): array
    {
        $rows = $this->db->query("SELECT i.integration_key AS `key`, i.display_name, i.category, i.data_source, i.access_mode, i.status, i.notes, i.last_sync_at,
          (SELECT COUNT(*) FROM provider_observations po WHERE po.integration_key = i.integration_key) AS observation_count,
          (SELECT COUNT(DISTINCT po.site_id) FROM provider_observations po WHERE po.integration_key = i.integration_key AND po.status IN ('success','empty')) AS observed_site_count,
          (SELECT COUNT(*) FROM site_platform_status sps WHERE sps.integration_key = i.integration_key AND sps.state <> 'unconfigured') AS configured_site_count,
          (SELECT COUNT(*) FROM site_platform_status sps WHERE sps.integration_key = i.integration_key AND sps.state IN ('verified','submitted','crawled','indexed','ranking')) AS verified_site_count
          FROM integrations i ORDER BY FIELD(i.category, 'search','ai','analytics'), i.id")->fetchAll();
        $labels = ['official' => '官方接口', 'public' => '公开采集', 'manual' => '人工证据'];
        foreach ($rows as &$row) {
            $row['data_source_label'] = $labels[$row['data_source']] ?? $row['data_source'];
        }
        return $rows;
    }

    private function aiMatrix(): array
    {
        $rows = $this->db->query("SELECT i.integration_key AS `key`, i.display_name AS name, i.status,
          (SELECT COUNT(*) FROM ai_evidence ae WHERE ae.provider_key = i.integration_key AND ae.brand_mentioned = 1) AS mention_count,
          (SELECT COUNT(*) FROM ai_evidence ae WHERE ae.provider_key = i.integration_key AND ae.cited_url IS NOT NULL) AS citation_count,
          (SELECT MAX(ae.captured_at) FROM ai_evidence ae WHERE ae.provider_key = i.integration_key) AS last_evidence_at
          FROM integrations i WHERE i.category = 'ai' ORDER BY i.id")->fetchAll();
        foreach ($rows as &$row) {
            if ($row['last_evidence_at'] === null) {
                $row['mention_count'] = null;
                $row['citation_count'] = null;
            }
        }
        return $rows;
    }

    private function evidence(): array
    {
        return $this->db->query('SELECT ae.public_id AS id, s.public_id AS site_id, s.name AS site_name, ae.provider_key, ae.provider_name, ae.prompt, ae.answer_excerpt, ae.brand_mentioned, ae.cited_url, ae.source_type, ae.captured_at FROM ai_evidence ae LEFT JOIN sites s ON s.id = ae.site_id ORDER BY ae.captured_at DESC LIMIT 200')->fetchAll();
    }

    private function publicUser(array $user): array
    {
        return ['id' => $user['public_id'], 'username' => $user['username'], 'display_name' => $user['display_name'], 'role' => $user['role']];
    }
}
