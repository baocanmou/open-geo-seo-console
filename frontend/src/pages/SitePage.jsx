import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Bot,
  CalendarDays,
  Database,
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
  ['content', 'GEO核心'],
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

const geoDimensionLabels = {
  retrievability: '检索可达性',
  entity_clarity: '实体清晰度',
  answerability: '答案可提取性',
  evidence_trust: '证据与信任',
  citation_readiness: '引用就绪度',
  freshness: '时效透明度',
  localization: '地域与语言',
  machine_readability: '机器可读性',
}

function GeoCorePanel({ core, pages = [], showPages = false }) {
  if (!core) {
    return <EmptyState title="自有 GEO 核心等待首次采集" detail="无需第三方账号。系统会从公开页面计算 8 维信号、全站一致性和可执行优先级。" />
  }
  const coverage = core.coverage || {}
  const dimensions = Object.entries(core.dimensions || {})
  const recommendations = core.recommendations || []
  const hasScore = core.overall_score !== null && core.overall_score !== undefined
  return (
    <div className="geo-core-panel">
      <div className="geo-core-summary">
        <div className="geo-core-score"><small>BCM-GEO Core</small><strong>{hasScore ? Math.round(Number(core.overall_score)) : '待采集'}</strong><span>v{core.algorithm_version}</span></div>
        <div className="geo-core-coverage">
          <span><small>30 天累计页面</small><strong>{formatNumber(coverage.page_count)}</strong></span>
          <span><small>本轮有效 / 尝试</small><strong>{formatNumber(coverage.pages_sampled_this_run)} / {formatNumber(coverage.pages_attempted_this_run)}</strong></span>
          <span><small>本轮发现候选</small><strong>{formatNumber(coverage.pages_discovered_this_run)}</strong></span>
          <span><small>robots 策略允许</small><strong>{coverage.bot_policy_allow_rate == null ? '待测' : `${Math.round(Number(coverage.bot_policy_allow_rate))}%`}</strong></span>
          <span><small>受控 HTTP 探测</small><strong>{coverage.bot_http_probe_rate == null ? '未启用' : `${Math.round(Number(coverage.bot_http_probe_rate))}%`}</strong></span>
          <span><small>重复正文</small><strong>{formatNumber(coverage.duplicate_page_count)}</strong></span>
          <span><small>实体一致性</small><strong>{coverage.entity_consistency == null ? '待识别' : `${Math.round(Number(coverage.entity_consistency))}%`}</strong></span>
          <span><small>滚动证据窗口</small><strong>{formatNumber(coverage.rolling_window_days || 30)} 天</strong></span>
        </div>
      </div>
      <div className="geo-dimension-grid">
        {dimensions.map(([key, value]) => (
          <div className="geo-dimension" key={key}>
            <span><strong>{geoDimensionLabels[key] || key}</strong><em>{Math.round(Number(value))}</em></span>
            <i><b style={{ width: `${Math.max(0, Math.min(100, Number(value)))}%` }} /></i>
          </div>
        ))}
      </div>
      <div className="geo-core-boundary">该分数只反映可核验的公开页面信号，不把抓取、提交或 llms.txt 误报为收录、排名或 AI 推荐。</div>
      {recommendations.length > 0 && (
        <div className="geo-recommendations">
          <h3>算法优先建议</h3>
          {recommendations.slice(0, 6).map((item) => (
            <article key={`${item.dimension}-${item.title}`}>
              <span>{Math.round(Number(item.priority_score))}</span>
              <div><strong>{item.title}</strong><p>{item.action}</p><small>{item.label} · 影响 {formatNumber(item.affected_pages)} 页 · 置信度 {Math.round(Number(item.confidence) * 100)}%</small></div>
            </article>
          ))}
        </div>
      )}
      {showPages && pages.length > 0 && (
        <div className="table-scroll geo-page-table">
          <table className="data-table compact-table">
            <thead><tr><th>页面</th><th>GEO分数</th><th>识别意图</th><th>主体实体</th></tr></thead>
            <tbody>
              {pages.map((page) => (
                <tr key={page.final_url}>
                  <td><span className="truncate-url" title={page.final_url}>{page.final_url}</span></td>
                  <td><ScoreStatus score={page.overall_score} /></td>
                  <td>{(page.intents || []).join('、') || '待识别'}</td>
                  <td>{(page.primary_entities || []).slice(0, 2).join('、') || '未声明'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
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
  if (!bots.length) return <EmptyState title="暂无爬虫策略证据" detail="完成采集后检查 robots.txt；受控 HTTP 探测为可选项。" />
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
              <td title={bot.evidence || ''}>{bot.http_status == null ? '未主动冒充探测' : `HTTP ${bot.http_status}`}</td>
              <td><StatusPill status={bot.access_allowed ? 'healthy' : 'warning'}>{bot.http_status == null ? (bot.robots_allowed ? '策略允许' : '策略限制') : (bot.access_allowed ? '探测通过' : '探测失败')}</StatusPill></td>
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

function PublicObservationTable({ observations }) {
  if (!observations.length) {
    return <EmptyState title="公开数据尚未采集" detail="系统将按站点分批采集 Common Crawl 与自托管 Lighthouse 证据。" />
  }

  const describe = (item) => {
    const summary = item.summary || {}
    if (item.integration_key === 'common_crawl') {
      return `开放语料样本 ${formatNumber(summary.indexed_pages)} 页 · 最近抓取 ${summary.latest_capture_at || '暂无'} UTC`
    }
    if (item.integration_key === 'lighthouse_local') {
      return `性能 ${formatNumber(summary.performance_score)} · SEO ${formatNumber(summary.seo_score)} · LCP ${formatNumber(summary.lcp_ms)}ms`
    }
    return summary.error || '已保存可追溯公开证据'
  }

  return (
    <div className="table-scroll">
      <table className="data-table compact-table">
        <thead><tr><th>公开来源</th><th>状态</th><th>证据摘要</th><th>采集时间</th><th>哈希</th></tr></thead>
        <tbody>
          {observations.map((item) => (
            <tr key={item.id}>
              <td><strong>{item.display_name}</strong></td>
              <td><StatusPill status={item.status === 'success' ? 'healthy' : item.status} /></td>
              <td>{describe(item)}</td>
              <td>{formatDate(item.observed_at)}</td>
              <td><span className="truncate-url" title={item.evidence_hash}>{item.evidence_hash?.slice(0, 12) || '—'}</span></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function PlatformStatusTable({ statuses }) {
  if (!statuses.length) {
    return <EmptyState title="逐站平台台账尚未初始化" detail="部署数据库迁移后自动建立 Google、百度及国际站长平台状态。" />
  }
  return (
    <div className="table-scroll">
      <table className="data-table compact-table">
        <thead><tr><th>平台</th><th>站点属性</th><th>阶段</th><th>验证方式</th><th>最近同步</th></tr></thead>
        <tbody>
          {statuses.map((item) => (
            <tr key={item.integration_key}>
              <td><strong>{item.display_name}</strong></td>
              <td><span className="truncate-url" title={item.property_uri}>{item.property_uri || '待添加'}</span></td>
              <td><StatusPill status={item.state} /></td>
              <td>{item.verification_method || '待配置'}</td>
              <td>{formatDate(item.last_sync_at)}</td>
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
  const {
    site,
    findings,
    bot_checks: bots,
    integrations,
    evidence,
    public_observations: publicObservations = [],
    platform_statuses: platformStatuses = [],
    geo_core: geoCore,
    geo_pages: geoPages = [],
    tasks,
  } = data

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
          {(activeTab === 'overview' || activeTab === 'content' || activeTab === 'pages') && (
            <article className="panel">
              <div className="panel-heading"><div><h2>自有 GEO 核心算法</h2><p>不依赖第三方账号的 8 维公开证据分析 · {geoCore?.coverage?.rolling_window_days || 30} 天滚动窗口</p></div><Network size={19} /></div>
              <GeoCorePanel core={geoCore} pages={geoPages} showPages={activeTab === 'pages'} />
            </article>
          )}
          {(activeTab === 'overview' || activeTab === 'issues' || activeTab === 'pages' || activeTab === 'content') && (
            <article className="panel">
              <FindingsTable findings={activeTab === 'content' ? findings.filter((item) => item.category === 'geo') : findings} />
            </article>
          )}

          {(activeTab === 'overview' || activeTab === 'issues') && (
            <div className="site-lower-grid">
              <article className="panel">
                <div className="panel-heading"><div><h2>爬虫策略与可达性</h2><p>robots.txt 全量解析；HTTP 用受控采集证据，不把伪装 User-Agent 当成真实官方爬虫。</p></div></div>
                <BotMatrix bots={bots} />
              </article>
              <article className="panel">
                <div className="panel-heading"><div><h2>搜索引擎覆盖</h2><p>官方接口、公开采集与待授权分层</p></div></div>
                <EngineCoverage integrations={integrations} />
              </article>
            </div>
          )}

          {activeTab === 'overview' && (
            <article className="panel">
              <div className="panel-heading"><div><h2>免费公开数据证据</h2><p>Common Crawl 开放语料与自托管 Lighthouse 实验室数据</p></div><Database size={19} /></div>
              <PublicObservationTable observations={publicObservations} />
            </article>
          )}

          {(activeTab === 'overview' || activeTab === 'ai') && (
            <article className="panel">
              <div className="panel-heading"><div><h2>AI推荐证据时间线</h2><p>保存提示词、回答、提及、引用与时间</p></div><Bot size={19} /></div>
              <EvidenceTimeline evidence={evidence} />
            </article>
          )}

          {activeTab === 'engines' && (
            <>
              <article className="panel">
                <div className="panel-heading"><div><h2>搜索引擎连接</h2><p>全局连接器与最近同步状态</p></div></div>
                <EngineCoverage integrations={integrations} />
              </article>
              <article className="panel">
                <div className="panel-heading"><div><h2>逐站接入阶段</h2><p>已添加、已验证、已提交、已抓取、已收录、已有排名分开记录</p></div></div>
                <PlatformStatusTable statuses={platformStatuses} />
              </article>
            </>
          )}
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
