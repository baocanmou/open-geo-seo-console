import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Bot,
  CalendarDays,
  ChevronLeft,
  Clock3,
  Download,
  ExternalLink,
  FileSearch,
  Globe2,
  ListChecks,
  Play,
  Search,
  ShieldCheck,
  Network,
} from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { api } from '../api'
import { EmptyState, ErrorState, LoadingState, ScoreStatus, StatusPill } from '../components/UI'
import { formatDate, formatNumber, severityLabel } from '../utils'

const tabs = [
  ['overview', '总览'],
  ['pages', '页面'],
  ['keywords', '关键词'],
  ['engines', '搜索引擎'],
  ['ai', 'AI推荐'],
  ['content', '内容机会'],
  ['issues', '问题'],
  ['settings', '设置'],
]

function SourceStrip({ data }) {
  const run = data.latest_run
  return (
    <section className="source-strip">
      <div><Globe2 size={20} /><span><small>公网采集</small><StatusPill status={run?.status === 'completed' ? 'healthy' : run?.status || 'pending'} /></span></div>
      <div><ShieldCheck size={20} /><span><small>官方接口</small><StatusPill status={data.official_authorized ? 'authorized' : 'unconfigured'} /></span></div>
      <div><Clock3 size={20} /><span><small>最近采集时间</small><strong>{formatDate(data.site.last_audit_at)}</strong></span></div>
      <div><Network size={20} /><span><small>发现 URL 数</small><strong>{formatNumber(run?.pages_discovered)}</strong></span></div>
    </section>
  )
}

function SiteScoreBand({ site }) {
  const metrics = [
    ['技术SEO', site.technical_score, ShieldCheck],
    ['内容GEO', site.geo_score, FileSearch],
    ['搜索覆盖', site.search_score, Search],
    ['AI可见度', site.ai_score, Bot],
  ]
  return (
    <section className="site-score-band">
      {metrics.map(([label, score, Icon]) => (
        <div key={label}><Icon size={22} /><strong>{label}</strong><ScoreStatus score={score} /></div>
      ))}
    </section>
  )
}

function FindingsTable({ findings }) {
  const [severity, setSeverity] = useState('all')
  const filtered = useMemo(() => findings.filter((item) => severity === 'all' || item.severity === severity), [findings, severity])

  if (!findings.length) return <EmptyState title="暂未发现问题" detail="若站点尚未采集，请先点击“立即采集”。" />

  return (
    <>
      <div className="sub-toolbar">
        <strong>技术与 GEO 问题 <small>共 {findings.length} 项</small></strong>
        <select value={severity} onChange={(event) => setSeverity(event.target.value)} aria-label="严重级别筛选">
          <option value="all">全部严重级别</option>
          <option value="critical">严重</option>
          <option value="high">高</option>
          <option value="medium">中</option>
          <option value="low">低</option>
        </select>
      </div>
      <div className="table-scroll">
        <table className="data-table issue-table">
          <thead><tr><th>级别</th><th>问题</th><th>影响页面</th><th>证据</th><th>修复建议</th><th>状态</th></tr></thead>
          <tbody>
            {filtered.map((finding) => (
              <tr key={finding.id}>
                <td><span className={`severity-marker severity-${finding.severity}`}>{severityLabel(finding.severity)}</span></td>
                <td><strong>{finding.title}</strong><small>{finding.category}</small></td>
                <td>{finding.affected_count || 1}<small className="truncate-url" title={finding.affected_url}>{finding.affected_url || '全站'}</small></td>
                <td><span className="evidence-copy" title={finding.evidence}>{finding.evidence || '见采集快照'}</span></td>
                <td><span className="recommendation-copy" title={finding.recommendation}>{finding.recommendation}</span></td>
                <td><StatusPill status={finding.status || 'open'} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  )
}

function BotMatrix({ bots }) {
  if (!bots.length) return <EmptyState title="暂无爬虫可访问性证据" detail="完成采集后检查 robots.txt 与真实页面访问。" />
  return (
    <div className="table-scroll">
      <table className="data-table compact-table bot-table">
        <thead><tr><th>爬虫</th><th>用途</th><th>robots.txt</th><th>页面访问</th><th>状态</th></tr></thead>
        <tbody>
          {bots.map((bot) => (
            <tr key={bot.bot_name}>
              <td><strong>{bot.bot_name}</strong></td>
              <td>{bot.purpose_label}</td>
              <td className={bot.robots_allowed ? 'success-text' : 'danger-text'}>{bot.robots_allowed ? '允许' : '限制'}</td>
              <td>{bot.http_status || '—'}</td>
              <td><StatusPill status={bot.access_allowed ? 'healthy' : 'warning'}>{bot.access_allowed ? '正常' : '需处理'}</StatusPill></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function EngineCoverage({ integrations }) {
  const engines = integrations.filter((item) => item.category === 'search')
  return (
    <div className="table-scroll">
      <table className="data-table compact-table">
        <thead><tr><th>搜索引擎</th><th>接入方式</th><th>状态</th><th>最近采集</th><th>说明</th></tr></thead>
        <tbody>
          {engines.map((item) => (
            <tr key={item.key}>
              <td><strong>{item.display_name}</strong></td>
              <td>{item.access_mode}</td>
              <td><StatusPill status={item.status} /></td>
              <td>{formatDate(item.last_sync_at)}</td>
              <td>{item.notes}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function EvidenceTimeline({ evidence }) {
  if (!evidence.length) {
    return <EmptyState title="尚无 AI 推荐证据" detail="配置模型连接或导入人工快照后，保存提示词、回答、品牌提及与引用链接。" />
  }
  return (
    <div className="table-scroll">
      <table className="data-table compact-table evidence-table">
        <thead><tr><th>提供方</th><th>Prompt / 问题</th><th>品牌提及</th><th>引用链接</th><th>抓取时间</th></tr></thead>
        <tbody>
          {evidence.map((item) => (
            <tr key={item.id}>
              <td><strong>{item.provider_name}</strong></td>
              <td title={item.prompt}>{item.prompt}</td>
              <td><StatusPill status={item.brand_mentioned ? 'healthy' : 'neutral'}>{item.brand_mentioned ? '是' : '否'}</StatusPill></td>
              <td>{item.cited_url ? <span className="truncate-url" title={item.cited_url}>{item.cited_url}</span> : '—'}</td>
              <td>{formatDate(item.captured_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function ActionRail({ tasks }) {
  return (
    <aside className="panel site-action-rail">
      <div className="panel-heading"><div><h2>优先处理事项</h2><p>严重问题优先</p></div><ListChecks size={19} /></div>
      {tasks.length === 0 && <EmptyState title="暂无待处理任务" />}
      <div className="priority-groups">
        {['critical', 'high', 'medium', 'low'].map((priority) => {
          const rows = tasks.filter((task) => task.priority === priority && task.status !== 'resolved')
          if (!rows.length) return null
          return (
            <section key={priority} className={`priority-group priority-${priority}`}>
              <h3>{severityLabel(priority)}优先级 <span>{rows.length}</span></h3>
              {rows.slice(0, 4).map((task) => (
                <article key={task.id}>
                  <strong>{task.title}</strong>
                  <small>{task.assignee || '未分配'} · {task.status === 'in_progress' ? '进行中' : '待处理'}</small>
                </article>
              ))}
            </section>
          )
        })}
      </div>
      <Link to="/tasks" className="button button-secondary rail-button">查看全部任务中心</Link>
    </aside>
  )
}

export default function SitePage() {
  const { siteId } = useParams()
  const [activeTab, setActiveTab] = useState('overview')
  const [state, setState] = useState({ loading: true, data: null, error: '' })
  const [auditing, setAuditing] = useState(false)
  const [notice, setNotice] = useState('')

  const load = useCallback(async () => {
    try {
      const data = await api.site(siteId)
      setState({ loading: false, data, error: '' })
    } catch (error) {
      setState({ loading: false, data: null, error: error.message })
    }
  }, [siteId])

  useEffect(() => {
    load()
  }, [load])

  const startAudit = async () => {
    setAuditing(true)
    setNotice('')
    try {
      const result = await api.auditSite(siteId)
      setNotice(result.message || '采集任务已进入队列')
      await load()
    } catch (error) {
      setNotice(error.message)
    } finally {
      setAuditing(false)
    }
  }

  if (state.loading) return <LoadingState label="正在读取站点证据" />
  if (state.error) return <ErrorState message={state.error} onRetry={load} />

  const data = state.data
  const { site, findings, bot_checks: bots, integrations, evidence, tasks } = data

  return (
    <div className="page site-page">
      <Link to="/sites" className="back-link"><ChevronLeft size={16} />站点资产</Link>
      <header className="site-header">
        <div className="site-title-lockup">
          <span className="site-large-icon"><Globe2 size={28} /></span>
          <span><h1>{site.name}</h1><p>{site.domain}</p></span>
        </div>
        <div className="page-actions">
          <span className="select-control select-static"><CalendarDays size={16} />近30天</span>
          <button className="button button-primary" type="button" onClick={startAudit} disabled={auditing}>
            <Play size={16} fill="currentColor" />{auditing ? '正在入队' : '立即采集'}
          </button>
          <button className="button button-secondary" type="button" onClick={() => window.print()}><Download size={16} />导出报告</button>
        </div>
      </header>
      {notice && <p className="inline-notice" role="status">{notice}</p>}

      <SourceStrip data={data} />
      <SiteScoreBand site={site} />

      <nav className="section-tabs" aria-label="站点详情">
        {tabs.map(([key, label]) => (
          <button key={key} type="button" className={activeTab === key ? 'active' : ''} onClick={() => setActiveTab(key)}>{label}</button>
        ))}
      </nav>

      <section className="site-layout">
        <div className="site-main-column">
          {(activeTab === 'overview' || activeTab === 'issues' || activeTab === 'pages' || activeTab === 'content') && (
            <article className="panel">
              <FindingsTable findings={activeTab === 'content' ? findings.filter((item) => item.category === 'geo') : findings} />
            </article>
          )}

          {(activeTab === 'overview' || activeTab === 'issues') && (
            <div className="site-lower-grid">
              <article className="panel">
                <div className="panel-heading"><div><h2>爬虫可访问性</h2><p>robots.txt 规则与真实 HTTP 响应分开检查</p></div></div>
                <BotMatrix bots={bots} />
              </article>
              <article className="panel">
                <div className="panel-heading"><div><h2>搜索引擎覆盖</h2><p>官方接口、公开采集与待授权分层</p></div></div>
                <EngineCoverage integrations={integrations} />
              </article>
            </div>
          )}

          {(activeTab === 'overview' || activeTab === 'ai') && (
            <article className="panel">
              <div className="panel-heading"><div><h2>AI推荐证据时间线</h2><p>保存提示词、回答、提及、引用与时间</p></div><Bot size={19} /></div>
              <EvidenceTimeline evidence={evidence} />
            </article>
          )}

          {activeTab === 'engines' && <article className="panel"><EngineCoverage integrations={integrations} /></article>}
          {activeTab === 'keywords' && <article className="panel"><EmptyState title="关键词数据待授权" detail="Google、Bing、百度等官方数据接入后按查询词、页面、国家与设备分析。" /></article>}
          {activeTab === 'settings' && (
            <article className="panel settings-summary">
              <h2>采集设置</h2>
              <dl>
                <div><dt>Canonical URL</dt><dd>{site.canonical_url}</dd></div>
                <div><dt>最大页面数</dt><dd>{site.max_pages}</dd></div>
                <div><dt>采集计划</dt><dd>{site.audit_schedule}</dd></div>
                <div><dt>站点状态</dt><dd><StatusPill status={site.status} /></dd></div>
              </dl>
              <p>域名与采集范围只允许管理员在服务器受控配置中调整，防止任意 URL 触发 SSRF。</p>
            </article>
          )}
        </div>
        <ActionRail tasks={tasks} />
      </section>

      <footer className="site-evidence-foot">
        <span>审计运行：{data.latest_run?.id || '待采集'}</span>
        <span>发现页面：{formatNumber(data.latest_run?.pages_discovered)}</span>
        <span>已审计：{formatNumber(data.latest_run?.pages_audited)}</span>
        {site.canonical_url && <span><ExternalLink size={14} />站点链接仅作证据，不自动打开</span>}
      </footer>
    </div>
  )
}
