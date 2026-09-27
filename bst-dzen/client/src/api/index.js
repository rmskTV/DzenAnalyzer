const BASE = '/api'

function csrfToken() {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)
  return match ? decodeURIComponent(match[1]) : null
}

async function request(path, options = {}) {
  const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) }
  const token = csrfToken()
  if (token && options.method && options.method !== 'GET') {
    headers['X-XSRF-TOKEN'] = token
  }

  const res = await fetch(`${BASE}${path}`, { ...options, headers })

  if (res.status === 401 && !path.startsWith('/auth/')) {
    window.location.href = '/login'
    throw new Error('401: не авторизован')
  }
  if (!res.ok) {
    const body = await res.text()
    throw new Error(`${res.status}: ${body}`)
  }
  return res.json()
}

export const api = {
  get: (path) => request(path),
  put: (path, data) => request(path, { method: 'PUT', body: JSON.stringify(data) }),
  post: (path, data) => request(path, { method: 'POST', body: JSON.stringify(data) }),
  csrf: () => fetch('/sanctum/csrf-cookie'),
}
