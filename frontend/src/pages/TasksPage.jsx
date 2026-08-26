import { useCallback, useEffect, useMemo, useState } from 'react'
import { CheckCircle2, Filter, ListChecks } from 'lucide-react'
import { api } from '../api'
import { EmptyState, ErrorState, LoadingState, PageHeader, StatusPill } from '../components/UI'
import { formatDate, severityLabel } from '../utils'

export default function TasksPage() {
  const [state, setState] = useState({ loading: true, data: [], error: '' })
  const [filter, setFilter] = useState('active')
  const [updating, setUpdating] = useState('')

  const load = useCallback(async () => {
    try {
      const data = await api.tasks()
      setState({ loading: false, data, error: '' })
    } catch (error) {
      setState({ loading: false, data: [], error: error.message })
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const tasks = useMemo(() => state.data.filter((task) => {
    if (filter === 'all') return true
    if (filter === 'resolved') return task.status === 'resolved'
    return task.status !== 'resolved'
  }), [filter, state.data])

  const updateTask = async (task, patch) => {
    setUpdating(task.id)
    try {
      await api.updateTask(task.id, patch)
      await load()
    } finally {
      setUpdating('')
    }
  }

  if (state.loading) return <LoadingState label="正在读取任务" />
  if (state.error) return <ErrorState message={state.error} onRetry={load} />

  return (
    <div className="page">
      <PageHeader title="任务中心" description="审计问题自动形成任务；状态变化保留操作日志。" />
      <section className="toolbar">
        <span className="toolbar-count"><ListChecks size={16} />{tasks.length} 项</span>
        <label className="select-control">
          <Filter size={15} />
          <select value={filter} onChange={(event) => setFilter(event.target.value)}>
            <option value="active">待处理与进行中</option>
            <option value="resolved">已完成</option>
            <option value="all">全部</option>
          </select>
        </label>
      </section>
      <section className="panel">
        {tasks.length === 0 ? (
          <EmptyState title="当前筛选下没有任务" detail="运行站点采集后，高优问题会自动进入任务中心。" />
        ) : (
          <div className="task-board">
            {tasks.map((task) => (
              <article className="task-row" key={task.id}>
                <span className={`severity-marker severity-${task.priority}`}>{severityLabel(task.priority)}</span>
                <span className="task-main">
                  <small>{task.site_name || '平台'} · {task.category || '综合'}</small>
                  <strong>{task.title}</strong>
                  <p>{task.description || '打开对应站点查看问题证据与修复建议。'}</p>
                </span>
                <span className="task-meta">
                  <StatusPill status={task.status} />
                  <small>{formatDate(task.created_at)}</small>
                </span>
                <select
                  aria-label={`更新${task.title}状态`}
                  value={task.status}
                  disabled={updating === task.id}
                  onChange={(event) => updateTask(task, { status: event.target.value })}
                >
                  <option value="open">待处理</option>
                  <option value="in_progress">进行中</option>
                  <option value="resolved">已完成</option>
                </select>
                {task.status !== 'resolved' && (
                  <button
                    className="icon-button"
                    type="button"
                    onClick={() => updateTask(task, { status: 'resolved' })}
                    disabled={updating === task.id}
                    aria-label={`完成${task.title}`}
                  >
                    <CheckCircle2 size={18} />
                  </button>
                )}
              </article>
            ))}
          </div>
        )}
      </section>
    </div>
  )
}
