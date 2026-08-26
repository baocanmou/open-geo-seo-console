import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  AlertCircle,
  Bot,
  CalendarDays,
  CheckCircle2,
  ChevronRight,
  ClipboardList,
  FileSearch,
  Globe2,
  Radio,
  Search,
  ShieldCheck,
} from 'lucide-react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { DataSourceLegend, EmptyState, ErrorState, LoadingState, PageHeader, ScoreStatus, StatusPill } from '../components/UI'
import { formatDate, formatNumber, severityLabel, statusTone } from '../utils'

const engineColors = {
  Google: '#2f6fed',
  百度: '#00a884',
  Bing: '#23a8d2',
  '360': '#f59e0b',
  搜狗: '#dc4c4c',
  Yandex: '#7c5ce7',
}

function VisibilityChart({ rows = [] }) {
  const series = useMemo(() => {
    const grouped = new Map()
    rows.forEach((row) => {
      if (!grouped.has(row.engine)) grouped.set(row.engine, [])
      grouped.get(row.engine).push(row)
    })
    return [...grouped.entries()]
  }, [rows])

  if (!series.length) {
    return (
      <EmptyState
        title="搜索表现等待官方数据"
        detail="接入 Google Search Console、Bing Webmaster 或导入平台证据后生成真实趋势。"
      />
    )
  }

  const allValues = rows.map((row) => Number(row.visibility_score || 0))
  const max = Math.max(...allValues, 1)

  return (
    <div className="trend-chart">
      <div className="chart-legend">
        {series.map(([engine]) => (
          <span key={engine}><i style={{ background: engineColors[engine] || '#64748b' }} />{engine}</span>
        ))}
      </div>
      <svg viewBox="0 0 660 240" role="img" aria-label="搜索可见度真实趋势">
        {[30, 80, 130, 180, 230].map((y) => <line key={y} x1="18" x2="650" y1={y} y2={y} className="chart-grid" />)}
        {series.map(([engine, points]) => {
          const coordinates = points.map((point, index) => {
            const x = 20 + (index / Math.max(points.length - 1, 1)) * 625
            const y = 225 - (Number(point.visibility_score || 0) / max) * 185
            return `${x},${y}`
          }).join(' ')
          return <polyline key={engine} points={coordinates} style={{ stroke: engineColors[engine] || '#64748b' }} />
        })}
      </svg>
    </div>
  )
}

function MetricBand({ summary }) {
  const items = [
    { label: '站点总数', value: formatNumber(summary.site_count), detail: `${formatNumber(summary.audited_count)} 个已有审计`, icon: Globe2, tone: 'info' },
    { label: '技术SEO', value: summary.technical_average, detail: '已审计站点均值', icon: ShieldCheck, score: true },
    { label: '内容GEO', value: summary.geo_average, detail: '可引用内容基础', icon: FileSearch, score: true },
    { label: '搜索覆盖', value: summary.search_authorized, detail: '已授权连接', icon: Search, tone: summary.search_authorized ? 'success' : 'warning' },
    { label: 'AI证据', value: formatNumber(summary.ai_evidence_count), detail: '可追溯回答快照', icon: Bot, tone: summary.ai_evidence_count ? 'success' : 'neutral' },
    { label: '高优任务', value: formatNumber(summary.high_priority_tasks), detail: '严重与高优问题', icon: ClipboardList, tone: summary.high_priority_tasks ? 'danger' : 'success' },
    { label: '最近采集', value: summary.last_collected_at ? '正常' : '待采集', detail: formatDate(summary.last_collected_at), icon: Radio, tone: summary.last_collected_at ? 'success' : 'neutral' },
  ]

  return (
    <section className="metric-band" aria-label="全站核心状态">
      {items.map((item) => {
        const Icon = item.icon
        return (
          <div className="metric-item" key={item.label}>
            <Icon size={22} className={`tone-${item.tone || 'info'}`} />
            <span>
              <small>{item.label}</small>
              {item.score ? <ScoreStatus score={item.value} /> : <strong className={`metric-value tone-${item.tone || 'info'}`}>{item.value}</strong>}
              <em>{item.detail}</em>
            </span>
          </div>
        )
      })}
    </section>
  )
}

export default function DashboardPage() {
  const [state, setState] = useState({ loading: true, data: null, error: '' })

  const load = useCallback(async () => {
    setState((current) => ({ ...current, loading: true, error: '' }))
    try {
      const data = await api.dashboard()
      setState({ loading: false, data, error: '' })
    } catch (error) {
      setState({ loading: false, data: null, error: error.message })
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  if (state.loading && !state.data) return <LoadingState label="正在汇总站点与证据" />
  if (state.error && !state.data) return <ErrorState message={state.error} onRetry={load} />

  const { summary, sites, tasks, integrations, ai_matrix: aiMatrix, trend } = state.data

  return (
    <div className="page dashboard-page">
      <PageHeader
        title="全站增长指挥台"
        description="SEO 与 GEO 统一监控；所有结论保留来源、时间与证据。"
        actions={(
          <>
            <label className="select-control">
              <Globe2 size={16} />
              <select aria-label="站点范围" defaultValue="all">
                <option value="all">全部站点</option>
                {sites.map((site) => <option value={site.id} key={site.id}>{site.name}</option>)}
              </select>
            </label>
            <span className="select-control select-static"><CalendarDays size={16} />近30天</span>
          </>
        )}
      />

      <MetricBand summary={summary} />

      <section className="dashboard-grid">
        <article className="panel trend-panel">
          <div className="panel-heading">
            <div><h2>搜索可见度趋势</h2><p>只展示已接入或已导入的真实快照</p></div>
            <StatusPill status={summary.search_authorized ? 'healthy' : 'unconfigured'}>
              {summary.search_authorized ? '已有数据' : '待授权'}
            </StatusPill>
          </div>
          <VisibilityChart rows={trend} />
        </article>

        <article className="panel ai-matrix-panel">
          <div className="panel-heading">
            <div><h2>AI推荐与提及矩阵</h2><p>搜索、检索与训练爬虫分开判断</p></div>
            <Link to="/ai" className="text-link">全部证据 <ChevronRight size={15} /></Link>
          </div>
          <div className="table-scroll">
            <table className="data-table compact-table">
              <thead>
                <tr><th>AI平台</th><th>接入</th><th>品牌提及</th><th>引用链接</th><th>最近证据</th></tr>
              </thead>
              <tbody>
                {aiMatrix.map((row) => (
                  <tr key={row.key}>
                    <td><strong>{row.name}</strong></td>
                    <td><StatusPill status={row.status} /></td>
                    <td>{row.mention_count ?? '—'}</td>
                    <td>{row.citation_count ?? '—'}</td>
                    <td>{formatDate(row.last_evidence_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </article>

        <aside className="panel task-rail">
          <div className="panel-heading">
            <div><h2>关键任务队列</h2><p>按风险与影响排序</p></div>
            <Link to="/tasks" className="text-link">全部任务</Link>
          </div>
          <div className="task-list">
            {tasks.length === 0 && <EmptyState title="当前没有高优任务" detail="完成采集后，问题会自动形成任务。" />}
            {tasks.slice(0, 6).map((task) => (
              <Link to={task.site_id ? `/sites/${task.site_id}` : '/tasks'} className="task-card" key={task.id}>
                <span className={`severity-dot severity-${task.priority}`}>{severityLabel(task.priority).slice(0, 1)}</span>
                <span>
                  <small>{task.site_name || '平台任务'}</small>
                  <strong>{task.title}</strong>
                  <em>{formatDate(task.created_at)}</em>
                </span>
                <ChevronRight size={16} />
              </Link>
            ))}
          </div>
        </aside>

        <article className="panel site-table-panel">
          <div className="panel-heading">
            <div><h2>站点健康总览</h2><p>{formatNumber(sites.length)} 个公开站点与站群入口</p></div>
            <Link to="/sites" className="text-link">管理站点 <ChevronRight size={15} /></Link>
          </div>
          <div className="table-scroll">
            <table className="data-table site-health-table">
              <thead>
                <tr>
                  <th>站点</th><th>技术SEO</th><th>内容GEO</th><th>搜索覆盖</th><th>AI证据</th><th>高优任务</th><th>最近采集</th><th aria-label="操作" />
                </tr>
              </thead>
              <tbody>
                {sites.map((site) => (
                  <tr key={site.id}>
                    <td>
                      <span className="site-cell">
                        <i className={`site-state site-state-${statusTone(site.audit_status)}`} />
                        <span><strong>{site.name}</strong><small>{site.domain}</small></span>
                      </span>
                    </td>
                    <td><ScoreStatus score={site.technical_score} /></td>
                    <td><ScoreStatus score={site.geo_score} /></td>
                    <td><StatusPill status={site.search_status} /></td>
                    <td>{formatNumber(site.ai_evidence_count)}</td>
                    <td className={site.high_priority_tasks ? 'danger-text' : ''}>{formatNumber(site.high_priority_tasks)}</td>
                    <td>{formatDate(site.last_audit_at)}</td>
                    <td><Link to={`/sites/${site.id}`} className="icon-link" aria-label={`查看${site.name}`}><ChevronRight size={17} /></Link></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </article>

        <article className="panel provenance-panel">
          <div className="panel-heading">
            <div><h2>数据来源与状态</h2><p>没有授权的数据保持空白，不以估算冒充真实结果</p></div>
          </div>
          <div className="provenance-list">
            <span><ShieldCheck size={18} /><strong>官方接口</strong><em>{integrations.filter((item) => item.data_source === 'official' && item.status === 'authorized').length} 个已授权</em></span>
            <span><Radio size={18} /><strong>公开采集</strong><em>站点技术与可访问性</em></span>
            <span><CheckCircle2 size={18} /><strong>证据快照</strong><em>{formatNumber(summary.ai_evidence_count)} 条 AI 证据</em></span>
            <span><AlertCircle size={18} /><strong>待授权</strong><em>{integrations.filter((item) => item.status === 'unconfigured').length} 个连接</em></span>
          </div>
          <DataSourceLegend compact />
        </article>
      </section>
    </div>
  )
}
