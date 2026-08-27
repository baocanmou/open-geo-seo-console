let csrfToken = ''

export class ApiError extends Error {
  constructor(message, status, details = null) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.details = details
  }
}

export function setCsrfToken(token) {
  csrfToken = typeof token === 'string' ? token : ''
}

async function request(path, options = {}) {
  const method = options.method || 'GET'
  const headers = {
    Accept: 'application/json',
    ...options.headers,
  }

  if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
    headers['Content-Type'] = 'application/json'
    if (csrfToken) headers['X-CSRF-Token'] = csrfToken
  }

  const response = await fetch(path, {
    ...options,
    method,
    headers,
    credentials: 'same-origin',
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
  })

  const payload = await response.json().catch(() => ({
    ok: false,
    error: { message: '服务器返回了无法解析的响应' },
  }))

  if (!response.ok || payload.ok === false) {
    throw new ApiError(payload.error?.message || '请求失败', response.status, payload.error?.details)
  }

  if (payload.csrf_token) setCsrfToken(payload.csrf_token)
  return payload.data
}

export const api = {
  health: () => request('/api/health'),
  session: () => request('/api/auth/me'),
  login: (username, password) => request('/api/auth/login', {
    method: 'POST',
    body: { username, password },
  }),
  logout: () => request('/api/auth/logout', { method: 'POST', body: {} }),
  accountSecurity: () => request('/api/account/security'),
  changePassword: (currentPassword, newPassword, confirmation) => request('/api/account/password', {
    method: 'POST',
    body: {
      current_password: currentPassword,
      new_password: newPassword,
      new_password_confirmation: confirmation,
    },
  }),
  dashboard: () => request('/api/dashboard'),
  sites: () => request('/api/sites'),
  site: (id) => request(`/api/sites/${encodeURIComponent(id)}`),
  auditSite: (id) => request(`/api/sites/${encodeURIComponent(id)}/audit`, {
    method: 'POST',
    body: {},
  }),
  tasks: () => request('/api/tasks'),
  updateTask: (id, body) => request(`/api/tasks/${encodeURIComponent(id)}`, {
    method: 'PATCH',
    body,
  }),
  integrations: () => request('/api/integrations'),
  evidence: () => request('/api/evidence'),
}
