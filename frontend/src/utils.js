export const statusLabels = {
  healthy: '正常',
  warning: '需处理',
  critical: '高风险',
  pending: '待采集',
  authorized: '已授权',
  unconfigured: '待授权',
  running: '采集中',
  failed: '失败',
  open: '待处理',
  in_progress: '进行中',
  resolved: '已完成',
  completed: '已完成',
}

export function statusLabel(value) {
  return statusLabels[value] || value || '待采集'
}

export function statusTone(value) {
  if (['healthy', 'authorized', 'resolved', 'completed'].includes(value)) return 'success'
  if (['critical', 'failed'].includes(value)) return 'danger'
  if (['warning', 'open', 'unconfigured'].includes(value)) return 'warning'
  if (['running', 'in_progress'].includes(value)) return 'info'
  return 'neutral'
}

export function scoreState(score) {
  if (score === null || score === undefined) return { label: '待采集', tone: 'neutral' }
  if (Number(score) >= 85) return { label: '正常', tone: 'success' }
  if (Number(score) >= 65) return { label: '需提升', tone: 'warning' }
  return { label: '需处理', tone: 'danger' }
}

export function formatDate(value) {
  if (!value) return '待采集'
  const date = new Date(value.replace(' ', 'T') + (value.includes('Z') ? '' : '+08:00'))
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat('zh-CN', {
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(date)
}

export function formatNumber(value) {
  return new Intl.NumberFormat('zh-CN').format(Number(value || 0))
}

export function severityLabel(value) {
  return {
    critical: '严重',
    high: '高',
    medium: '中',
    low: '低',
    info: '提示',
  }[value] || value
}
