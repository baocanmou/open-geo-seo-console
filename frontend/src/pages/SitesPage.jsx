import { useCallback, useEffect, useMemo, useState } from 'react'
import { ChevronRight, Globe2, RefreshCw, Search } from 'lucide-react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { ErrorState, LoadingState, PageHeader, ScoreStatus, StatusPill } from '../components/UI'
import { formatDate } from '../utils'

export default function SitesPage() {
  const [query, setQuery] = useState('')
  const [state, setState] = useState({ loading: true, data: [], error: '' })

  const load = useCallback(async () => {
    try {
      const data = await api.sites()
      setState({ loading: false, data, error: '' })
    } catch (error) {
      setState({ loading: false, data: [], error: error.message })
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase()
    if (!needle) return state.data
    return state.data.filter((site) => [site.name, site.domain, site.group_name].filter(Boolean).some((value) => value.toLowerCase().includes(needle)))
  }, [query, state.data])

  if (state.loading) return <LoadingState label="正在读取站点资产" />
  if (state.error) return <ErrorState message={state.error} onRetry={load} />

  return (
    <div className="page">
      <PageHeader
        title="站点资产"
        description="主要网站、城市站群与客户站点统一管理；每个域名保留独立采集证据。"
        actions={<button className="button button-secondary" type="button" onClick={load}><RefreshCw size={16} />刷新</button>}
      />

      <section className="toolbar">
        <label className="search-control">
          <Search size={17} />
          <input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="搜索站点、域名或分组" />
        </label>
        <span className="toolbar-count"><Globe2 size={16} />{filtered.length} 个站点</span>
      </section>

      <section className="panel">
        <div className="table-scroll">
          <table className="data-table sites-table">
            <thead>
              <tr><th>站点</th><th>分组</th><th>技术SEO</th><th>内容GEO</th><th>审计状态</th><th>页面</th><th>最近采集</th><th aria-label="操作" /></tr>
            </thead>
            <tbody>
              {filtered.map((site) => (
                <tr key={site.id}>
                  <td><span className="site-cell"><i className="site-symbol"><Globe2 size={16} /></i><span><strong>{site.name}</strong><small>{site.canonical_url}</small></span></span></td>
                  <td>{site.group_name || '独立站点'}</td>
                  <td><ScoreStatus score={site.technical_score} /></td>
                  <td><ScoreStatus score={site.geo_score} /></td>
                  <td><StatusPill status={site.audit_status} /></td>
                  <td>{site.pages_audited ?? '—'} / {site.pages_discovered ?? '—'}</td>
                  <td>{formatDate(site.last_audit_at)}</td>
                  <td><Link className="icon-link" to={`/sites/${site.id}`} aria-label={`打开${site.name}`}><ChevronRight size={18} /></Link></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  )
}
