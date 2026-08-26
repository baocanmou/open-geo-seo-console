INSERT INTO integrations
  (integration_key, display_name, category, data_source, access_mode, status, notes, created_at, updated_at)
VALUES
  ('lighthouse_local', '自托管 Lighthouse', 'analytics', 'public', '隔离的开源 Lighthouse CLI', 'public', '在专用低权限账号和私网出站隔离下串行采集性能、SEO、无障碍与最佳实践实验室证据；不代表排名。', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  display_name = VALUES(display_name),
  category = VALUES(category),
  data_source = VALUES(data_source),
  access_mode = VALUES(access_mode),
  status = VALUES(status),
  notes = VALUES(notes),
  updated_at = NOW();

UPDATE integrations
SET display_name = 'Google PageSpeed Insights（可选）',
    access_mode = 'Google PageSpeed API',
    status = 'disabled',
    notes = '当前中国服务器访问超时，保留为可选远程数据源；不得把失败或提交当作排名。',
    updated_at = NOW()
WHERE integration_key = 'pagespeed_insights';
