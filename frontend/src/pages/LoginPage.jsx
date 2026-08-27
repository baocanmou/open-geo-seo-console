import { useState } from 'react'
import { ArrowRight, Eye, EyeOff, LockKeyhole, Radar, ShieldCheck } from 'lucide-react'

export default function LoginPage({ onLogin, initialError }) {
  const [username, setUsername] = useState('bcm')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState(initialError || '')

  const submit = async (event) => {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    try {
      await onLogin(username.trim(), password)
    } catch (requestError) {
      setError(requestError.message || '登录失败')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="login-page">
      <section className="login-brand-panel">
        <div className="login-brand">
          <span className="brand-orbit"><span /></span>
          <strong>Open GEO</strong>
        </div>
        <div className="login-message">
          <Radar size={48} strokeWidth={1.35} />
          <h1>看清每个网站，<br />再决定下一步增长。</h1>
          <p>统一管理技术 SEO、搜索覆盖、AI 推荐证据与内容机会。</p>
        </div>
        <div className="login-proof">
          <span><ShieldCheck size={17} /> 私有部署</span>
          <span><LockKeyhole size={17} /> 数据分层</span>
        </div>
      </section>

      <main className="login-form-panel">
        <form className="login-form" onSubmit={submit}>
          <div>
            <h2>登录指挥中心</h2>
            <p>仅限授权管理员使用</p>
          </div>
          <label>
            <span>账号</span>
            <input
              autoComplete="username"
              value={username}
              onChange={(event) => setUsername(event.target.value)}
              required
              maxLength={64}
            />
          </label>
          <label>
            <span>密码</span>
            <span className="password-input">
              <input
                type={showPassword ? 'text' : 'password'}
                autoComplete="current-password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                required
                maxLength={200}
              />
              <button type="button" onClick={() => setShowPassword((value) => !value)} aria-label={showPassword ? '隐藏密码' : '显示密码'}>
                {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
              </button>
            </span>
          </label>
          {error && <p className="form-error" role="alert">{error}</p>}
          <button className="button button-primary login-submit" type="submit" disabled={submitting}>
            {submitting ? '正在验证' : '进入平台'}
            {!submitting && <ArrowRight size={18} />}
          </button>
          <small>连续失败会触发临时锁定；平台不会在浏览器保存登录令牌。</small>
        </form>
      </main>
    </div>
  )
}
