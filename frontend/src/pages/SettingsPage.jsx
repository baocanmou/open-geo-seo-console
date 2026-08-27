import { useEffect, useState } from 'react'
import { Eye, EyeOff, KeyRound, ShieldCheck } from 'lucide-react'
import { api } from '../api'
import { ErrorState, LoadingState, PageHeader } from '../components/UI'

const emptyForm = {
  currentPassword: '',
  newPassword: '',
  confirmation: '',
}

export default function SettingsPage() {
  const [policy, setPolicy] = useState(null)
  const [loadError, setLoadError] = useState('')
  const [form, setForm] = useState(emptyForm)
  const [visible, setVisible] = useState({ current: false, next: false, confirmation: false })
  const [submitting, setSubmitting] = useState(false)
  const [message, setMessage] = useState({ type: '', text: '' })

  useEffect(() => {
    let active = true
    api.accountSecurity()
      .then((data) => {
        if (active) setPolicy(data)
      })
      .catch((error) => {
        if (active) setLoadError(error.message || '账户安全策略读取失败')
      })
    return () => {
      active = false
    }
  }, [])

  const updateField = (field, value) => {
    setForm((current) => ({ ...current, [field]: value }))
    if (message.text) setMessage({ type: '', text: '' })
  }

  const toggleVisible = (field) => {
    setVisible((current) => ({ ...current, [field]: !current[field] }))
  }

  const submit = async (event) => {
    event.preventDefault()
    setMessage({ type: '', text: '' })
    if (form.newPassword !== form.confirmation) {
      setMessage({ type: 'error', text: '两次输入的新密码不一致' })
      return
    }

    setSubmitting(true)
    try {
      await api.changePassword(form.currentPassword, form.newPassword, form.confirmation)
      setForm(emptyForm)
      setVisible({ current: false, next: false, confirmation: false })
      setMessage({ type: 'success', text: '密码已更新，其他设备上的登录会话已失效。' })
    } catch (error) {
      setMessage({ type: 'error', text: error.message || '密码修改失败' })
    } finally {
      setSubmitting(false)
    }
  }

  if (!policy && !loadError) return <LoadingState label="正在读取账户安全策略" />
  if (loadError) return <ErrorState message={loadError} onRetry={() => window.location.reload()} />

  const minimumLength = Number(policy.password_min_length) || 16
  const passwordField = (label, field, autoComplete) => (
    <label>
      <span>{label}</span>
      <span className="password-input">
        <input
          type={visible[field] ? 'text' : 'password'}
          autoComplete={autoComplete}
          value={form[field === 'current' ? 'currentPassword' : field === 'next' ? 'newPassword' : 'confirmation']}
          onChange={(event) => updateField(field === 'current' ? 'currentPassword' : field === 'next' ? 'newPassword' : 'confirmation', event.target.value)}
          minLength={field === 'current' ? undefined : minimumLength}
          maxLength={200}
          required
        />
        <button type="button" onClick={() => toggleVisible(field)} aria-label={visible[field] ? `隐藏${label}` : `显示${label}`}>
          {visible[field] ? <EyeOff size={18} /> : <Eye size={18} />}
        </button>
      </span>
    </label>
  )

  return (
    <div className="page">
      <PageHeader title="账户与安全" description="修改管理员密码，并立即撤销其他设备上的旧登录会话。" />
      <div className="security-layout">
        <section className="panel security-form-panel">
          <div className="panel-heading">
            <div>
              <h2>修改登录密码</h2>
              <p>需要先验证当前密码；新密码只会以单向哈希保存。</p>
            </div>
            <KeyRound size={22} />
          </div>
          <form className="settings-form" onSubmit={submit}>
            {passwordField('当前密码', 'current', 'current-password')}
            {passwordField('新密码', 'next', 'new-password')}
            {passwordField('确认新密码', 'confirmation', 'new-password')}
            <small>新密码至少 {minimumLength} 个字符，不能与账号或当前密码相同。</small>
            {message.text && <p className={message.type === 'success' ? 'form-success' : 'form-error'} role={message.type === 'success' ? 'status' : 'alert'}>{message.text}</p>}
            <button className="button button-primary settings-submit" type="submit" disabled={submitting}>
              {submitting ? '正在安全更新' : '更新密码'}
            </button>
          </form>
        </section>
        <aside className="panel security-note">
          <ShieldCheck size={28} />
          <h2>安全机制</h2>
          <ul>
            <li>必须提供当前密码</li>
            <li>受 CSRF 与来源校验保护</li>
            <li>连续失败会临时锁定</li>
            <li>更新后撤销其他登录会话</li>
            <li>审计日志不记录密码内容</li>
          </ul>
        </aside>
      </div>
    </div>
  )
}
