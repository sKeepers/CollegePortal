const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '/api'

async function request(path, options = {}) {
  const token = localStorage.getItem('college_portal_token')
  const isFormData = options.body instanceof FormData

  const response = await fetch(`${API_BASE_URL}${path}`, {
    headers: {
      Accept: 'application/json',
      ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
    ...options,
  })

  if (response.status === 204) {
    return null
  }

  const payload = await response.json().catch(() => ({}))

  if (!response.ok) {
    const validationMessages = payload.errors
      ? Object.values(payload.errors).flat().filter(Boolean)
      : []
    const message = validationMessages[0] || payload.message || 'Запрос не выполнен'
    const error = new Error(message)
    error.status = response.status
    error.errors = payload.errors || {}
    throw error
  }

  return payload
}

export const api = {
  baseUrl: API_BASE_URL,

  setToken(token) {
    localStorage.setItem('college_portal_token', token)
  },

  clearToken() {
    localStorage.removeItem('college_portal_token')
  },

  token() {
    return localStorage.getItem('college_portal_token')
  },

  async login(credentials) {
    return request('/auth/login', {
      method: 'POST',
      body: JSON.stringify(credentials),
    })
  },

  async me() {
    return request('/auth/me')
  },

  async logout() {
    return request('/auth/logout', {
      method: 'POST',
    })
  },

  async get(resource, params = {}) {
    return this.list(resource, params)
  },

  async post(resource, data = {}) {
    return request(`/${resource}`, {
      method: 'POST',
      body: JSON.stringify(data),
    })
  },

  async list(resource, params = {}) {
    const query = new URLSearchParams()

    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        query.set(key, value)
      }
    })

    const suffix = query.toString() ? `?${query.toString()}` : ''
    return request(`/${resource}${suffix}`)
  },

  async create(resource, data) {
    return request(`/${resource}`, {
      method: 'POST',
      body: JSON.stringify(data),
    })
  },

  async update(resource, id, data) {
    return request(`/${resource}/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    })
  },

  async put(resource, data) {
    return request(`/${resource}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    })
  },

  async delete(resource, id) {
    return request(`/${resource}/${id}`, {
      method: 'DELETE',
    })
  },

  async upload(path, formData) {
    return request(path, {
      method: 'POST',
      body: formData,
    })
  },

  async postDownload(path, data) {
    const token = localStorage.getItem('college_portal_token')
    const response = await fetch(`${API_BASE_URL}${path}`, {
      method: 'POST',
      headers: {
        Accept: 'text/csv',
        'Content-Type': 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        'X-Idempotency-Key': crypto.randomUUID?.() || String(Date.now()),
      },
      body: JSON.stringify(data),
    })

    if (!response.ok) {
      const payload = await response.json().catch(() => ({}))
      throw new Error(payload.message || 'Файл не удалось скачать')
    }

    return response.blob()
  },

  async download(path) {
    const token = localStorage.getItem('college_portal_token')
    const response = await fetch(`${API_BASE_URL}${path}`, {
      headers: {
        Accept: 'text/csv',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    })

    if (!response.ok) {
      const payload = await response.json().catch(() => ({}))
      throw new Error(payload.message || 'Файл не удалось скачать')
    }

    return response.blob()
  },
}
