import { useCallback, useEffect, useMemo, useState } from 'react'
import { Bot, Database, KeyRound, Radio, Search, ShieldCheck } from 'lucide-react'
import { api } from '../api'
import { DataSourceLegend, ErrorState, LoadingState, PageHeader, StatusPill } from '../components/UI'
import { formatDate } from '../utils'

const categoryMeta = {
  search: { label: '搜索引擎', icon: Search },
  ai: { label: 'AI 推荐', icon: Bot },
  analytics: { label: '分析与性能', icon: Database },
}

export default function IntegrationsPage() {
  const [category, setCategory] = useState('all')
  const [state, setState] = useState({ loading: true, data: [], error: '' })

  const load = useCallback(async () => {
    try {
      const data = await api.integrations()
      setState({ loading: false, data, error: '' })
    } catch (error) {
      setState({ loading: false, data: [], error: error.message })
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const filtered = useMemo(() => state.data.filter((item) => category === 'all' || item.category === category), [category, state.data])

  if (state.loading) return <LoadingState label="正在读取平台连接" />
  if (state.error) return <ErrorState message={state.error} onRetry={load} />

  return (
    <div className="page">
      <PageHeader title="集成" description="官方接口、公开采集和人工证据分层管理；密钥只在服务器加密保存。" />
      <section className="integration-summary">
        <div><ShieldCheck size={20} /><strong>{state.data.filter((item) => item.status === 'authorized').length}</strong><span>已授权</span></div>
        <div><Radio size={20} /><strong>{state.data.filter((item) => item.data_source === 'public').length}</strong><span>公开采集</span></div>
        <div><KeyRound size={20} /><strong>{state.data.filter((item) => item.status === 'unconfigured').length}</strong><span>待授权</span></div>
      </section>
      <section className="toolbar integration-tabs">
        <button type="button" className={category === 'all' ? 'active' : ''} onClick={() => setCategory('all')}>全部</button>
        {Object.entries(categoryMeta).map(([key, meta]) => (
          <button type="button" className={category === key ? 'active' : ''} onClick={() => setCategory(key)} key={key}>{meta.label}</button>
        ))}
      </section>
      <section className="integration-list">
        {filtered.map((item) => {
          const MetaIcon = categoryMeta[item.category]?.icon || Database
          return (
            <article className="integration-row" key={item.key}>
              <span className="integration-icon"><MetaIcon size={21} /></span>
              <span className="integration-name">
                <strong>{item.display_name}</strong>
                <small>{item.notes}</small>
              </span>
              <span><small>数据来源</small><strong>{item.data_source_label}</strong></span>
              <span><small>接入方式</small><strong>{item.access_mode}</strong></span>
              <span><small>最近采集</small><strong>{formatDate(item.last_sync_at)}</strong></span>
              <StatusPill status={item.status} />
              <button className="button button-secondary" type="button" disabled>
                {item.status === 'authorized' ? '已连接' : item.data_source === 'public' ? '自动采集' : '待配置'}
              </button>
            </article>
          )
        })}
      </section>
      <DataSourceLegend />
    </div>
  )
}
