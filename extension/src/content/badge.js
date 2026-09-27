// Мини-плашка на страницах dzen.ru: быстрая статистика текущего канала,
// кнопки «сделать своим» / «в конкуренты» / «открыть дашборд».
// Shadow DOM, чтобы не конфликтовать со стилями Дзена.

import { fetchChannelMeta } from '../lib/dzenApi.js'
import { getSettings, getChannel, saveSettings } from '../lib/store.js'
import { median, matured } from '../lib/metrics.js'

const SKIP_FIRST_SEGMENTS = new Set([
  'search', 'media', 'video', 'watch', 'feed', 'profile', 'id', 'signin', 'login',
  'settings', 'about', 'prf', 'nova', 'catalog', 'podborki', 'topics', 'company',
])

/** Ключ канала из URL: dzen.ru/<name> или dzen.ru/channel/<id> */
function channelFromLocation() {
  const path = location.pathname.replace(/^\/+/, '')
  if (!path) return null
  const [first, second] = path.split('/')
  if (first === 'channel' && second && /^[0-9a-f]{24}$/.test(second)) {
    return { key: second, mode: 'id' }
  }
  if (!first || first.includes('.') === false && SKIP_FIRST_SEGMENTS.has(first)) return null
  if (!/^[A-Za-z0-9_.-]+$/.test(first)) return null
  return { key: first, mode: 'name' }
}

const CSS = `
  .dzx-host { all: initial; }
  .panel {
    position: fixed; right: 16px; bottom: 16px; z-index: 2147483647;
    width: 300px; padding: 14px; border-radius: 16px;
    background: rgba(255, 255, 255, .97);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(15, 23, 42, .08);
    box-shadow: 0 12px 40px rgba(15, 23, 42, .22), 0 2px 8px rgba(15, 23, 42, .08);
    font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #0f172a;
    font-size: 13px; animation: dzx-in .28s cubic-bezier(.2, .8, .3, 1);
  }
  @keyframes dzx-in { from { opacity: 0; transform: translateY(10px) scale(.97); } }
  .head { display: flex; gap: 10px; align-items: center; margin: 0 26px 10px 0; }
  .avatar {
    flex: 0 0 36px; width: 36px; height: 36px; border-radius: 11px;
    background: linear-gradient(135deg, #4338ca, #7c3aed); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 16px;
  }
  .title { font-weight: 700; font-size: 14px; line-height: 1.25; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .key { color: #64748b; font-size: 11px; margin-top: 1px; }
  .close {
    position: absolute; top: 8px; right: 8px; width: 22px; height: 22px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 6px; cursor: pointer; color: #94a3b8; font-size: 12px;
  }
  .close:hover { background: #f1f5f9; color: #475569; }
  .stats { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px; }
  .stat {
    background: #f8fafc; border: 1px solid #eef2f7; border-radius: 12px; padding: 8px 10px;
  }
  .stat-value { display: block; font-size: 16px; font-weight: 700; letter-spacing: -.01em; }
  .stat-label { display: block; font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: .05em; margin-top: 2px; }
  .hint { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 10px; padding: 8px 10px; font-size: 12px; margin-bottom: 10px; }
  .actions { display: flex; flex-direction: column; gap: 6px; }
  .btn {
    display: flex; align-items: center; gap: 8px; width: 100%;
    padding: 8px 12px; border-radius: 10px; cursor: pointer; font-size: 13px;
    border: 1px solid #e2e8f0; background: #fff; color: #334155; text-align: left;
    transition: background .15s, border-color .15s, transform .1s;
  }
  .btn:hover { background: #f8fafc; border-color: #cbd5e1; }
  .btn:active { transform: scale(.98); }
  .btn .ico { width: 16px; text-align: center; flex: 0 0 16px; }
  .btn.own-active { background: #ecfdf5; border-color: #a7f3d0; color: #047857; font-weight: 600; }
  .btn.comp-active { background: #eef2ff; border-color: #c7d2fe; color: #4338ca; font-weight: 600; }
  .btn.primary {
    background: linear-gradient(135deg, #4338ca, #6d28d9); color: #fff; border: none;
    font-weight: 600; justify-content: center;
  }
  .btn.primary:hover { filter: brightness(1.08); }
  .dot {
    position: fixed; right: 16px; bottom: 16px; z-index: 2147483647;
    width: 48px; height: 48px; border-radius: 15px; cursor: pointer;
    background: linear-gradient(135deg, #4338ca, #7c3aed); color: #fff;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 10px 28px rgba(67, 56, 202, .5);
    transition: transform .15s, filter .15s;
    animation: dzx-in .28s cubic-bezier(.2, .8, .3, 1);
  }
  .dot:hover { transform: scale(1.07); filter: brightness(1.08); }
`

let host = null
let shadow = null
let expanded = true
let currentChannel = null
let metaCache = new Map()

// --- позиционирование -----------------------------------------------------
// CSS position:fixed на Дзене ненадёжен (transform/zoom/containment у предков
// «прибивают» элемент к документу, а лента бесконечная). Поэтому плашка
// стабилизируется сервоконтуром: каждый кадр измеряем фактический rect
// и доворачиваем left/top до правого нижнего угла вьюпорта. Сходится в любой
// системе координат и самовосстанавливается при любом скролле.

let servo = null

function stopServo() {
  if (servo) {
    cancelAnimationFrame(servo.raf)
    servo = null
  }
}

function startServo(el, label) {
  stopServo()
  el.style.position = 'fixed'
  el.style.right = 'auto'
  el.style.bottom = 'auto'
  let x = 16
  let y = window.innerHeight - el.getBoundingClientRect().height - 16
  const step = () => {
    el.style.left = `${Math.round(x)}px`
    el.style.top = `${Math.round(y)}px`
    const rect = el.getBoundingClientRect()
    const dx = window.innerWidth - 16 - (rect.left + rect.width)
    const dy = window.innerHeight - 16 - (rect.top + rect.height)
    if (Math.abs(dx) > 0.5 || Math.abs(dy) > 0.5) {
      // демпфирование 0.5: устойчиво и при масштабирующих transform у предков
      x += dx * 0.5
      y += dy * 0.5
      el.style.left = `${Math.round(x)}px`
      el.style.top = `${Math.round(y)}px`
    }
    if (servo) servo.raf = requestAnimationFrame(step)
  }
  servo = { raf: 0 }
  step()
  // диагностика: видно в консоли страницы
  setTimeout(() => {
    const rect = el.getBoundingClientRect()
    console.info(`[dzx] ${label}: left=${Math.round(rect.left)} top=${Math.round(rect.top)} w=${Math.round(rect.width)} viewport=${window.innerWidth}x${window.innerHeight}`)
  }, 800)
}

function ensureHost() {
  if (host) return
  host = document.createElement('div')
  host.className = 'dzx-host'
  document.documentElement.appendChild(host)
  shadow = host.attachShadow({ mode: 'open' })
  const style = document.createElement('style')
  style.textContent = CSS
  shadow.appendChild(style)
}

function esc(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))
}

async function metaFor(channel) {
  if (metaCache.has(channel.key)) return metaCache.get(channel.key)
  try {
    const meta = await fetchChannelMeta(channel.key, { mode: channel.mode })
    metaCache.set(channel.key, meta)
    return meta
  } catch {
    return null
  }
}

async function render() {
  ensureHost()
  const channel = channelFromLocation()
  if (!channel) {
    host.style.display = 'none'
    return
  }
  host.style.display = ''
  if (currentChannel?.key === channel.key && shadow.querySelector('.panel, .dot')) return
  currentChannel = channel
  stopServo()

  const [settings, stored, meta] = await Promise.all([getSettings(), getChannel(channel.key), metaFor(channel)])
  const title = stored?.meta?.title ?? meta?.title ?? channel.key
  const subscribers = stored?.meta?.subscribers ?? meta?.subscribers ?? null

  let statsHtml = ''
  if (stored?.posts?.length) {
    const maturedPosts = matured(stored.posts)
    const views = maturedPosts.map((p) => p.v)
    const perDay = (stored.posts.length / 21).toFixed(1)
    statsHtml = views.length
      ? `<div class="stats">
           <div class="stat"><span class="stat-value">${median(views).toLocaleString('ru-RU')}</span><span class="stat-label">медиана охвата</span></div>
           <div class="stat"><span class="stat-value">${perDay}</span><span class="stat-label">постов/день · n=${stored.posts.length}</span></div>
         </div>`
      : `<div class="stats"><div class="stat"><span class="stat-value">${stored.posts.length}</span><span class="stat-label">постов в базе (ещё не созрели)</span></div></div>`
  } else {
    statsHtml = '<div class="hint">Нет данных по каналу — соберите его на вкладке «Каналы» в дашборде.</div>'
  }

  const isOwn = settings.own === channel.key
  const isComp = settings.competitors.includes(channel.key)

  shadow.innerHTML = ''
  if (!expanded) {
    const dot = document.createElement('div')
    dot.className = 'dot'
    dot.title = 'Дзен-аналитика'
    dot.innerHTML = `<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
      <rect x="2" y="10" width="3.5" height="8" rx="1.2" fill="#fff" opacity=".75"/>
      <rect x="8.25" y="6" width="3.5" height="12" rx="1.2" fill="#fff" opacity=".9"/>
      <rect x="14.5" y="2" width="3.5" height="16" rx="1.2" fill="#fff"/>
    </svg>`
    dot.onclick = () => {
      expanded = true
      currentChannel = null
      render()
    }
    shadow.appendChild(dot)
    startServo(dot, 'dot')
    return
  }

  const avatarLetter = (title.trim()[0] ?? '?').toUpperCase()
  const panel = document.createElement('div')
  panel.className = 'panel'
  panel.innerHTML = `
    <span class="close" title="Свернуть">✕</span>
    <div class="head">
      <div class="avatar">${esc(avatarLetter)}</div>
      <div style="min-width:0">
        <div class="title">${esc(title)}</div>
        <div class="key">${esc(channel.key)}${subscribers ? ' · ' + Number(subscribers).toLocaleString('ru-RU') + ' подп.' : ''}</div>
      </div>
    </div>
    ${statsHtml}
    <div class="actions">
      <button class="btn ${isOwn ? 'own-active' : ''}" data-act="own">
        <span class="ico">${isOwn ? '★' : '☆'}</span>${isOwn ? 'Свой канал' : 'Сделать своим'}
      </button>
      <button class="btn ${isComp ? 'comp-active' : ''}" data-act="comp">
        <span class="ico">${isComp ? '−' : '+'}</span>${isComp ? 'Убрать из конкурентов' : 'Добавить в конкуренты'}
      </button>
      <button class="btn primary" data-act="dash">Открыть дашборд</button>
    </div>
  `
  panel.querySelector('.close').onclick = () => {
    expanded = false
    currentChannel = null
    render()
  }
  panel.addEventListener('click', async (e) => {
    const act = e.target.dataset?.act
    if (!act) return
    if (act === 'dash') {
      // окрываем через service worker: window.open из контент-скрипта —
      // навигация «от веб-страницы», Chrome её блокирует (ERR_BLOCKED_BY_CLIENT)
      try {
        chrome.runtime.sendMessage({ act: 'open-dashboard' })
      } catch {
        window.open(chrome.runtime.getURL('dashboard.html'), '_blank')
      }
      return
    }
    const s = await getSettings()
    if (act === 'own') {
      const next = { ...s, own: s.own === channel.key ? null : channel.key }
      await saveSettings(next)
    }
    if (act === 'comp') {
      const list = s.competitors.filter((k) => k !== channel.key)
      if (!s.competitors.includes(channel.key)) list.push(channel.key)
      await saveSettings({ ...s, competitors: list })
    }
    currentChannel = null
    render()
  })
  shadow.appendChild(panel)
  startServo(panel, 'panel')
}

try {
  render().catch((e) => console.error('[dzx] render failed:', e))
} catch (e) {
  console.error('[dzx] render failed:', e)
}
// Дзен — SPA: следим за сменой канала без перезагрузки
setInterval(() => {
  const channel = channelFromLocation()
  if (channel?.key !== currentChannel?.key) {
    render().catch((e) => console.error('[dzx] render failed:', e))
  }
}, 1500)
