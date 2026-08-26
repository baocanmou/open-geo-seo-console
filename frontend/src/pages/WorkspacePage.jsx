import { useCallback, useEffect, useMemo, useState } from 'react'
import { Bot, Download, FileSearch, Lightbulb, Search, Settings2 } from 'lucide-react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { DataSourceLegend, EmptyState, ErrorState, LoadingState, PageHeader, StatusPill } from '../components/UI'
import { formatDate, severityLabel } from '../utils'

const copy = {
  audits: { title: '技术审计', description: '跨站查看可验证的技术问题、影响页面和修复建议。', icon: FileSearch },
  search: { title: '搜索表现', description: '国内外搜索引擎数据按官方接口、公开证据和待授权分层。', icon: Search },
  ai: { title: 'AI 推荐', description: '跟踪主流 AI 的品牌提及、引用链接与回答证据。', icon: Bot },
  content: { title: '内容机会', description: '从技术审计与可引用性信号中识别内容改进优先级。', icon: Lightbulb },
  reports: { title: '报告', description: '按当前真实数据生成可打印报告，不替代收录和排名证明。', icon: Download },
  settings: { title: '平台设置', description: '查看采集、安全与数据保留策略。', icon: Settings2 },
}

export default function WorkspacePage({ mode }) {
  const meta = copy[mode]
  const [state, setState] = useState({ loading: true, dashboard: null, integrations: [], evidence: [], error: '' })

  const load = useCallback(async () => {
    try {
      const [dashboard, integrations, evidence] = await Promise.all([
        api.dashboard(),
        api.integrations(),
        mode === 'ai' ? api.evidence() : Promise.resolve([]),
      ])
      setState({ loading: false, dashboard, integrations, evidence, error: '' })
    } catch (error) {
      setState({ loading: false, dashboard: null, integrations: [], evidence: [], error: error.message })
    }
  }, [mode])

  useEffect(() => {
    load()
  }, [load])

  const contentFindings = useMemo(() => state.dashboard?.recent_findings?.filter((item) => item.category === 'geo') || [], [state.dashboard])

  if (state.loading) return <LoadingState />
  if (state.error) return <ErrorState message={state.error} onRetry={load} />

  const Icon = meta.icon
  const findings = mode === 'content' ? contentFindings : state.dashboard.recent_findings || []
  const engineIntegrations = state.integrations.filter((item) => item.category === 'search')
  const aiIntegrations = state.integrations.filter((item) => item.category === 'ai')

  return (
    <div className="page">
      <PageHeader
        title={meta.title}
        description={meta.description}
        actions={mode === 'reports' ? <button className="button button-primary" type="button" onClick={() => window.print()}><Download size={16} />打印当前报告</button> : null}
      />
      <section className="workspace-intro">
        <Icon size={27} />
        <span><strong>证据先于结论</strong><p>每条状态必须能回到来源、采集时间、原始 URL 或平台接口。</p></span>
      </section>

      {(mode === 'audits' || mode === 'content') && (
        <section className="panel">
          <div className="panel-heading"><div><h2>{mode === 'content' ? 'GEO 内容机会' : '最近跨站问题'}</h2><p>按严重度和最新采集排序</p></div></div>
          {findings.length === 0 ? <EmptyState title="等待采集结果" /> : (
            <div className="table-scroll">
              <table className="data-table">
                <thead><tr><th>级别</th><th>站点</th><th>问题</th><th>证据</th><th>时间</th><th /></tr></thead>
                <tbody>
                  {findings.map((finding) => (
                    <tr key={finding.id}>
                      <td><span className={`severity-marker severity-${finding.severity}`}>{severityLabel(finding.severity)}</span></td>
                      <td>{finding.site_name}</td>
                      <td><strong>{finding.title}</strong><small>{finding.category}</small></td>
                      <td>{finding.evidence}</td>
                      <td>{formatDate(finding.detected_at)}</td>
                      <td><Link className="text-link" to={`/sites/${finding.site_id}`}>查看</Link></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      )}

      {mode === 'search' && (
        <section className="panel">
          <div className="panel-heading"><div><h2>搜索引擎连接</h2><p>提交、抓取、收录与排名分别记录</p></div></div>
          <div className="workspace-grid">
            {engineIntegrations.map((item) => (
              <article key={item.key}><strong>{item.display_name}</strong><StatusPill status={item.status} /><p>{item.notes}</p><small>{item.access_mode} · {item.data_source_label}</small></article>
            ))}
          </div>
        </section>
      )}

      {mode === 'ai' && (
        <>
          <section className="panel">
            <div className="panel-heading"><div><h2>AI 平台连接</h2><p>回答采样必须保留原始提示词和引用证据</p></div></div>
            <div className="workspace-grid">
              {aiIntegrations.map((item) => (
                <article key={item.key}><strong>{item.display_name}</strong><StatusPill status={item.status} /><p>{item.notes}</p><small>{item.access_mode}</small></article>
              ))}
            </div>
          </section>
          <section className="panel">
            <div className="panel-heading"><div><h2>最近 AI 证据</h2><p>按抓取时间倒序</p></div></div>
            {state.evidence.length === 0 ? <EmptyState title="尚无 AI 回答证据" detail="在集成配置完成前保持空白。" /> : state.evidence.map((item) => <p key={item.id}>{item.provider_name} · {item.prompt}</p>)}
          </section>
        </>
      )}

      {mode === 'reports' && (
        <section className="report-sheet panel">
          <h2>Open GEO SEO 监测摘要</h2>
          <dl>
            <div><dt>纳管站点</dt><dd>{state.dashboard.summary.site_count}</dd></div>
            <div><dt>已有审计</dt><dd>{state.dashboard.summary.audited_count}</dd></div>
            <div><dt>高优任务</dt><dd>{state.dashboard.summary.high_priority_tasks}</dd></div>
            <div><dt>AI 证据</dt><dd>{state.dashboard.summary.ai_evidence_count}</dd></div>
          </dl>
          <p>报告生成时间：{new Intl.DateTimeFormat('zh-CN', { dateStyle: 'long', timeStyle: 'short' }).format(new Date())}</p>
          <DataSourceLegend />
        </section>
      )}

      {mode === 'settings' && (
        <section className="panel settings-summary">
          <h2>运行策略</h2>
          <dl>
            <div><dt>常规采集</dt><dd>每日分批，避免集中压测业务站点</dd></div>
            <div><dt>单站页面上限</dt><dd>默认 25，深度审计按站点单独调整</dd></div>
            <div><dt>外部请求</dt><dd>仅已登记域名与别名，拒绝私网与保留地址</dd></div>
            <div><dt>登录安全</dt><dd>HttpOnly Session、CSRF、Origin、失败锁定</dd></div>
            <div><dt>数据边界</dt><dd>未授权连接不产生模拟数据</dd></div>
          </dl>
        </section>
      )}
    </div>
  )
}
