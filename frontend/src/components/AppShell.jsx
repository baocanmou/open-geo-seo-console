import { useEffect, useState } from 'react'
import {
  BarChart3,
  Bot,
  ChevronDown,
  CircleGauge,
  FileChartColumn,
  FileSearch,
  Globe2,
  Lightbulb,
  ListChecks,
  LogOut,
  KeyRound,
  Menu,
  Network,
  Search,
  Settings,
  X,
} from 'lucide-react'
import { Link, NavLink, useLocation } from 'react-router-dom'

const navigation = [
  { to: '/', label: '概览', icon: CircleGauge },
  { to: '/sites', label: '站点', icon: Globe2 },
  { to: '/audits', label: '技术审计', icon: FileSearch },
  { to: '/search', label: '搜索表现', icon: Search },
  { to: '/ai', label: 'AI推荐', icon: Bot },
  { to: '/content', label: '内容机会', icon: Lightbulb },
  { to: '/tasks', label: '任务中心', icon: ListChecks },
  { to: '/integrations', label: '集成', icon: Network },
  { to: '/reports', label: '报告', icon: FileChartColumn },
  { to: '/settings', label: '设置', icon: Settings },
]

export default function AppShell({ user, onLogout, children }) {
  const [menuOpen, setMenuOpen] = useState(false)
  const [profileOpen, setProfileOpen] = useState(false)
  const location = useLocation()

  useEffect(() => {
    setMenuOpen(false)
    setProfileOpen(false)
  }, [location.pathname])

  return (
    <div className="app-shell">
      <button
        className="mobile-menu-button"
        type="button"
        onClick={() => setMenuOpen((value) => !value)}
        aria-label={menuOpen ? '关闭导航' : '打开导航'}
        aria-expanded={menuOpen}
      >
        {menuOpen ? <X size={21} /> : <Menu size={21} />}
      </button>

      <aside className={`sidebar ${menuOpen ? 'sidebar-open' : ''}`}>
        <div className="brand-lockup">
          <span className="brand-orbit"><span /></span>
          <div>
            <strong>Open GEO</strong>
            <small>增长指挥中心</small>
          </div>
        </div>

        <nav className="primary-nav" aria-label="主导航">
          {navigation.map(({ to, label, icon: Icon }) => (
            <NavLink
              key={to}
              to={to}
              end={to === '/'}
              className={({ isActive }) => (isActive ? 'nav-link nav-link-active' : 'nav-link')}
            >
              <Icon size={18} strokeWidth={1.9} aria-hidden="true" />
              <span>{label}</span>
            </NavLink>
          ))}
        </nav>

        <div className="sidebar-foot">
          <div className="sidebar-signal">
            <BarChart3 size={17} />
            <span>证据优先 · 数据分层</span>
          </div>
          <button className="profile-button" type="button" onClick={() => setProfileOpen((value) => !value)}>
            <span className="profile-avatar">{user.display_name?.slice(0, 1) || '管'}</span>
            <span>
              <strong>{user.display_name || user.username}</strong>
              <small>{user.role === 'admin' ? '管理员' : user.role}</small>
            </span>
            <ChevronDown size={16} className={profileOpen ? 'rotate-180' : ''} />
          </button>
          {profileOpen && (
            <div className="profile-menu">
              <Link to="/settings"><KeyRound size={16} />修改密码</Link>
              <button type="button" onClick={onLogout}><LogOut size={16} />退出登录</button>
            </div>
          )}
        </div>
      </aside>

      {menuOpen && <button className="mobile-overlay" type="button" aria-label="关闭导航" onClick={() => setMenuOpen(false)} />}
      <main className="main-canvas">{children}</main>
    </div>
  )
}
