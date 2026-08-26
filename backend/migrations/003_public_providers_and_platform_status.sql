CREATE TABLE IF NOT EXISTS provider_observations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id CHAR(36) NOT NULL UNIQUE,
  site_id BIGINT UNSIGNED NOT NULL,
  integration_key VARCHAR(80) NOT NULL,
  snapshot_date DATE NOT NULL,
  status ENUM('success','empty','error') NOT NULL,
  summary_json MEDIUMTEXT NOT NULL,
  source_reference VARCHAR(1000) NULL,
  evidence_hash CHAR(64) NOT NULL,
  observed_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_provider_site_day (site_id, integration_key, snapshot_date),
  INDEX idx_provider_integration_time (integration_key, observed_at),
  CONSTRAINT fk_provider_observation_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_platform_status (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  site_id BIGINT UNSIGNED NOT NULL,
  integration_key VARCHAR(80) NOT NULL,
  property_uri VARCHAR(1000) NULL,
  state ENUM('unconfigured','added','verified','submitted','crawled','indexed','ranking','error') NOT NULL DEFAULT 'unconfigured',
  verification_method VARCHAR(80) NULL,
  evidence_reference VARCHAR(1000) NULL,
  verified_at DATETIME NULL,
  last_sync_at DATETIME NULL,
  notes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_site_platform (site_id, integration_key),
  INDEX idx_platform_state (integration_key, state),
  CONSTRAINT fk_site_platform_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO integrations (integration_key, display_name, category, data_source, access_mode, status, notes, created_at, updated_at)
VALUES
  ('pagespeed_insights', 'PageSpeed Insights', 'analytics', 'public', '无需密钥低频 API / Lighthouse', 'public', '采集移动端性能、SEO、无障碍与最佳实践实验室证据；不代表排名。', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  display_name = VALUES(display_name),
  category = VALUES(category),
  data_source = VALUES(data_source),
  access_mode = VALUES(access_mode),
  notes = VALUES(notes),
  updated_at = NOW();

INSERT INTO site_platform_status
  (site_id, integration_key, property_uri, state, created_at, updated_at)
SELECT s.id, i.integration_key, s.canonical_url, 'unconfigured', NOW(), NOW()
FROM sites s
JOIN integrations i ON i.integration_key IN (
  'google_search_console',
  'baidu_search_resource',
  'bing_webmaster',
  'yandex_webmaster',
  'naver_search',
  'indexnow'
)
WHERE s.status = 'active'
ON DUPLICATE KEY UPDATE property_uri = VALUES(property_uri), updated_at = NOW();

UPDATE integrations
SET access_mode = 'Common Crawl CDXJ 开放索引',
    notes = '记录站点是否出现在最新开放网页语料索引及样本页面数；不代表任何 AI 产品采用、训练或推荐。',
    updated_at = NOW()
WHERE integration_key = 'common_crawl';
