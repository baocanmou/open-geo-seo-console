import { useCallback, useEffect, useState } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { api, ApiError, setCsrfToken } from './api'
import AppShell from './components/AppShell'
import DashboardPage from './pages/DashboardPage'
import IntegrationsPage from './pages/IntegrationsPage'
import LoginPage from './pages/LoginPage'
import SitePage from './pages/SitePage'
import SitesPage from './pages/SitesPage'
import TasksPage from './pages/TasksPage'
import WorkspacePage from './pages/WorkspacePage'

function App() {
  const [session, setSession] = useState({ loading: true, user: null })

  const loadSession = useCallback(async () => {
    try {
      const data = await api.session()
      setCsrfToken(data.csrf_token)
      setSession({ loading: false, user: data.user })
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        setSession({ loading: false, user: null })
        return
      }
      setSession({ loading: false, user: null, error: error.message })
    }
  }, [])

  useEffect(() => {
    loadSession()
  }, [loadSession])

  const handleLogin = async (username, password) => {
    const data = await api.login(username, password)
    setCsrfToken(data.csrf_token)
    setSession({ loading: false, user: data.user })
  }

  const handleLogout = async () => {
    await api.logout()
    setCsrfToken('')
    setSession({ loading: false, user: null })
  }

  if (session.loading) {
    return (
      <div className="app-loading" role="status">
        <span className="brand-orbit" aria-hidden="true" />
        <strong>正在连接 GEO 指挥中心</strong>
      </div>
    )
  }

  if (!session.user) {
    return <LoginPage onLogin={handleLogin} initialError={session.error} />
  }

  return (
    <AppShell user={session.user} onLogout={handleLogout}>
      <Routes>
        <Route path="/" element={<DashboardPage />} />
        <Route path="/sites" element={<SitesPage />} />
        <Route path="/sites/:siteId" element={<SitePage />} />
        <Route path="/audits" element={<WorkspacePage mode="audits" />} />
        <Route path="/search" element={<WorkspacePage mode="search" />} />
        <Route path="/ai" element={<WorkspacePage mode="ai" />} />
        <Route path="/content" element={<WorkspacePage mode="content" />} />
        <Route path="/tasks" element={<TasksPage />} />
        <Route path="/integrations" element={<IntegrationsPage />} />
        <Route path="/reports" element={<WorkspacePage mode="reports" />} />
        <Route path="/settings" element={<WorkspacePage mode="settings" />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </AppShell>
  )
}

export default App
