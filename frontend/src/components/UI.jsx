import { AlertTriangle, Database, LoaderCircle, Radio, ShieldCheck } from 'lucide-react'
import { statusLabel, statusTone } from '../utils'

export function StatusPill({ status, children }) {
  const tone = statusTone(status)
  return <span className={`status-pill status-${tone}`}>{children || statusLabel(status)}</span>
}

export function ScoreStatus({ score }) {
  if (score === null || score === undefined) return <StatusPill status="pending">待采集</StatusPill>
  const roundedScore = Math.round(Number(score))
  const status = roundedScore >= 85 ? 'healthy' : roundedScore >= 65 ? 'warning' : 'critical'
  return (
    <span className="score-status">
      <strong>{roundedScore}</strong>
      <StatusPill status={status} />
    </span>
  )
}

export function LoadingState({ label = '正在读取数据' }) {
  return (
    <div className="state-panel" role="status">
      <LoaderCircle className="spin" size={24} />
      <strong>{label}</strong>
    </div>
  )
}

export function ErrorState({ message, onRetry }) {
  return (
    <div className="state-panel state-error" role="alert">
      <AlertTriangle size={24} />
      <strong>{message || '数据读取失败'}</strong>
      {onRetry && <button className="button button-secondary" type="button" onClick={onRetry}>重新加载</button>}
    </div>
  )
}

export function EmptyState({ title, detail }) {
  return (
    <div className="empty-state">
      <Database size={24} />
      <strong>{title}</strong>
      {detail && <p>{detail}</p>}
    </div>
  )
}

export function DataSourceLegend({ compact = false }) {
  return (
    <div className={`source-legend ${compact ? 'source-legend-compact' : ''}`}>
      <span><ShieldCheck size={15} /> 官方接口</span>
      <span><Radio size={15} /> 公开采集</span>
      <span><Database size={15} /> 人工证据</span>
      {!compact && <small>各层独立记录，不用提交或可访问替代收录、排名和 AI 引用。</small>}
    </div>
  )
}

export function PageHeader({ title, description, actions, eyebrow }) {
  return (
    <header className="page-header">
      <div>
        {eyebrow && <p className="page-breadcrumb">{eyebrow}</p>}
        <h1>{title}</h1>
        {description && <p>{description}</p>}
      </div>
      {actions && <div className="page-actions">{actions}</div>}
    </header>
  )
}
