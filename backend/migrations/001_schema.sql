CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(64) PRIMARY KEY,
  applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(36) NOT NULL UNIQUE,
  username VARCHAR(64) NOT NULL UNIQUE,
  display_name VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','analyst','viewer') NOT NULL DEFAULT 'admin',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identity_hash CHAR(64) NOT NULL,
  succeeded TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL,
  INDEX idx_login_guard (identity_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sites (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(36) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  domain VARCHAR(253) NOT NULL UNIQUE,
  canonical_url VARCHAR(500) NOT NULL,
  group_name VARCHAR(160) NULL,
  status ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
  max_pages INT UNSIGNED NOT NULL DEFAULT 25,
  audit_schedule VARCHAR(80) NOT NULL DEFAULT 'daily-staggered',
  technical_score DECIMAL(5,2) NULL,
  geo_score DECIMAL(5,2) NULL,
  search_score DECIMAL(5,2) NULL,
  ai_score DECIMAL(5,2) NULL,
  last_audit_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_sites_status (status),
  INDEX idx_sites_group (group_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_aliases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id BIGINT UNSIGNED NOT NULL,
  hostname VARCHAR(253) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_site_alias (site_id, hostname),
  INDEX idx_alias_hostname (hostname),
  CONSTRAINT fk_alias_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(36) NOT NULL UNIQUE,
  site_id BIGINT UNSIGNED NOT NULL,
  trigger_type ENUM('manual','scheduled','initial') NOT NULL,
  status ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
  pages_discovered INT UNSIGNED NOT NULL DEFAULT 0,
  pages_audited INT UNSIGNED NOT NULL DEFAULT 0,
  technical_score DECIMAL(5,2) NULL,
  geo_score DECIMAL(5,2) NULL,
  error_message VARCHAR(500) NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_audit_site_time (site_id, created_at),
  INDEX idx_audit_status (status, created_at),
  CONSTRAINT fk_audit_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_audits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  audit_run_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NOT NULL,
  url VARCHAR(1000) NOT NULL,
  final_url VARCHAR(1000) NULL,
  http_status SMALLINT UNSIGNED NULL,
  content_type VARCHAR(160) NULL,
  title_text VARCHAR(500) NULL,
  meta_description TEXT NULL,
  canonical_url VARCHAR(1000) NULL,
  h1_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  word_count INT UNSIGNED NOT NULL DEFAULT 0,
  structured_types TEXT NULL,
  indexable TINYINT(1) NOT NULL DEFAULT 1,
  response_ms INT UNSIGNED NULL,
  captured_at DATETIME NOT NULL,
  INDEX idx_page_run (audit_run_id),
  INDEX idx_page_site (site_id),
  CONSTRAINT fk_page_run FOREIGN KEY (audit_run_id) REFERENCES audit_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_page_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS findings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(36) NOT NULL UNIQUE,
  audit_run_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NOT NULL,
  fingerprint CHAR(64) NOT NULL,
  code VARCHAR(80) NOT NULL,
  category ENUM('technical','geo','security','performance') NOT NULL,
  severity ENUM('critical','high','medium','low','info') NOT NULL,
  title VARCHAR(255) NOT NULL,
  evidence TEXT NULL,
  recommendation TEXT NOT NULL,
  affected_url VARCHAR(1000) NULL,
  affected_count INT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('open','acknowledged','resolved','ignored') NOT NULL DEFAULT 'open',
  detected_at DATETIME NOT NULL,
  INDEX idx_find_site_time (site_id, detected_at),
  INDEX idx_find_priority (status, severity),
  CONSTRAINT fk_find_run FOREIGN KEY (audit_run_id) REFERENCES audit_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_find_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(36) NOT NULL UNIQUE,
  site_id BIGINT UNSIGNED NULL,
  finding_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  category VARCHAR(80) NULL,
  priority ENUM('critical','high','medium','low') NOT NULL DEFAULT 'medium',
  status ENUM('open','in_progress','resolved') NOT NULL DEFAULT 'open',
  assignee VARCHAR(100) NULL,
  due_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  resolved_at DATETIME NULL,
  INDEX idx_task_status_priority (status, priority, created_at),
  CONSTRAINT fk_task_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL,
  CONSTRAINT fk_task_finding FOREIGN KEY (finding_id) REFERENCES findings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bot_checks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  audit_run_id BIGINT UNSIGNED NOT NULL,
  site_id BIGINT UNSIGNED NOT NULL,
  bot_name VARCHAR(100) NOT NULL,
  purpose ENUM('search','retrieval','training','extended') NOT NULL,
  purpose_label VARCHAR(100) NOT NULL,
  robots_allowed TINYINT(1) NOT NULL,
  http_status SMALLINT UNSIGNED NULL,
  access_allowed TINYINT(1) NOT NULL,
  evidence TEXT NULL,
  checked_at DATETIME NOT NULL,
  INDEX idx_bot_site_time (site_id, checked_at),
  CONSTRAINT fk_bot_run FOREIGN KEY (audit_run_id) REFERENCES audit_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_bot_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integrations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  integration_key VARCHAR(80) NOT NULL UNIQUE,
  display_name VARCHAR(160) NOT NULL,
  category ENUM('search','ai','analytics') NOT NULL,
  data_source ENUM('official','public','manual') NOT NULL,
  access_mode VARCHAR(160) NOT NULL,
  status ENUM('authorized','unconfigured','error','disabled','public') NOT NULL DEFAULT 'unconfigured',
  encrypted_config MEDIUMTEXT NULL,
  notes VARCHAR(500) NULL,
  last_sync_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS search_snapshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id BIGINT UNSIGNED NOT NULL,
  integration_key VARCHAR(80) NOT NULL,
  engine VARCHAR(80) NOT NULL,
  snapshot_date DATE NOT NULL,
  query_text VARCHAR(500) NULL,
  page_url VARCHAR(1000) NULL,
  country VARCHAR(8) NULL,
  device VARCHAR(40) NULL,
  clicks DECIMAL(14,2) NULL,
  impressions DECIMAL(14,2) NULL,
  ctr DECIMAL(8,6) NULL,
  average_position DECIMAL(8,3) NULL,
  visibility_score DECIMAL(8,3) NULL,
  source_reference VARCHAR(1000) NULL,
  imported_at DATETIME NOT NULL,
  INDEX idx_search_site_date (site_id, snapshot_date),
  CONSTRAINT fk_search_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_evidence (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(36) NOT NULL UNIQUE,
  site_id BIGINT UNSIGNED NULL,
  provider_key VARCHAR(80) NOT NULL,
  provider_name VARCHAR(160) NOT NULL,
  prompt TEXT NOT NULL,
  answer_excerpt TEXT NULL,
  brand_mentioned TINYINT(1) NOT NULL DEFAULT 0,
  cited_url VARCHAR(1000) NULL,
  source_type ENUM('official_api','manual_capture','public_response') NOT NULL,
  evidence_hash CHAR(64) NOT NULL,
  captured_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_ai_site_time (site_id, captured_at),
  CONSTRAINT fk_ai_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(36) NOT NULL UNIQUE,
  job_type VARCHAR(80) NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  status ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  error_message VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_jobs_queue (status, available_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  object_type VARCHAR(80) NULL,
  object_id VARCHAR(100) NULL,
  context_json TEXT NULL,
  ip_hash CHAR(64) NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_activity_time (created_at),
  CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
