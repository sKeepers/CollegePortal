const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '/api'
const TOKEN_KEY = 'college_portal_token'

function storedToken() {
  return localStorage.getItem(TOKEN_KEY) || sessionStorage.getItem(TOKEN_KEY)
}

const VALIDATION_RULE_MESSAGES = {
  'validation.exists': 'Выбранное значение не найдено. Обновите справочник и выберите значение из списка.',
  'validation.required': 'Заполните обязательное поле.',
  'validation.required_without': 'Заполните это поле или выберите существующую запись.',
  'validation.min.numeric': 'Значение меньше допустимого.',
  'validation.max.numeric': 'Значение больше допустимого.',
  'validation.email': 'Укажите корректный email.',
  'validation.date': 'Укажите корректную дату.',
  'validation.before': 'Дата должна быть раньше допустимого предела.',
  'validation.in': 'Выбранное значение недопустимо.',
}

const FIELD_LABELS = {
  applicant_id: 'Абитуриент',
  person_id: 'Личная карточка',
  education_program_id: 'Образовательная программа',
  source_id: 'Источник',
  status_id: 'Статус',
  admission_year: 'Год приема',
  application_number: 'Номер заявления',
  submitted_at: 'Дата подачи',
  last_name: 'Фамилия',
  first_name: 'Имя',
  middle_name: 'Отчество',
  birth_date: 'Дата рождения',
  phone: 'Телефон',
  email: 'Email',
  snils: 'СНИЛС',
  inn: 'ИНН',
  document_type_id: 'Тип документа',
  series: 'Серия',
  number: 'Номер',
  issue_date: 'Дата выдачи',
  issued_by: 'Кем выдан',
  subdivision_code: 'Код подразделения',
  address: 'Адрес',
  registration_address: 'Адрес регистрации',
  residential_address: 'Адрес проживания',
}

function readableField(field) {
  const normalized = String(field || '')
    .replace(/^person\./, '')
    .replace(/^identity_document\./, '')
    .replace(/^identity\./, '')
    .replace(/^education\./, '')
    .replace(/\.\d+\./g, '.')
  return FIELD_LABELS[normalized] || normalized.replaceAll('_', ' ')
}

export function humanizeApiMessage(message, field = '') {
  const text = String(message || '').trim()
  if (!text) return 'Запрос не выполнен'
  if (text === 'Forbidden.' || text === 'Forbidden' || text === 'This action is unauthorized.') {
    return 'У вас нет доступа к этому разделу или действию.'
  }

  const mapped = VALIDATION_RULE_MESSAGES[text]
  if (mapped) {
    return field ? `${readableField(field)}: ${mapped}` : mapped
  }

  return text
}

function validationMessages(errors) {
  if (!errors) return []

  return Object.entries(errors)
    .flatMap(([field, messages]) => (Array.isArray(messages) ? messages : [messages])
      .filter(Boolean)
      .map((message) => humanizeApiMessage(message, field)))
}

async function request(path, options = {}) {
  const token = storedToken()
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
    const messages = validationMessages(payload.errors)
    const message = messages[0] || humanizeApiMessage(payload.message) || 'Запрос не выполнен'
    const error = new Error(message)
    error.status = response.status
    error.errors = payload.errors || {}
    error.validationMessages = messages
    throw error
  }

  return payload
}

export const api = {
  baseUrl: API_BASE_URL,

  setToken(token, { persistent = true } = {}) {
    const storage = persistent ? localStorage : sessionStorage
    const otherStorage = persistent ? sessionStorage : localStorage
    storage.setItem(TOKEN_KEY, token)
    otherStorage.removeItem(TOKEN_KEY)
  },

  clearToken() {
    localStorage.removeItem(TOKEN_KEY)
    sessionStorage.removeItem(TOKEN_KEY)
  },

  token() {
    return storedToken()
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

  async patch(resource, data) {
    return request(`/${resource}`, {
      method: 'PATCH',
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
    const token = storedToken()
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
      throw new Error(humanizeApiMessage(payload.message) || 'Файл не удалось скачать')
    }

    return response.blob()
  },

  async download(path) {
    const token = storedToken()
    const response = await fetch(`${API_BASE_URL}${path}`, {
      headers: {
        Accept: 'text/csv',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    })

    if (!response.ok) {
      const payload = await response.json().catch(() => ({}))
      throw new Error(humanizeApiMessage(payload.message) || 'Файл не удалось скачать')
    }

    return response.blob()
  },
}
