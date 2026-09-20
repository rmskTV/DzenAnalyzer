const BASE = '/api'

async function request(path, options = {}) {
  const res = await fetch(`${BASE}${path}`, {
    headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
    ...options,
  })
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
}
