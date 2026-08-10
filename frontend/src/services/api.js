const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '/api'

// Токен сессии лежит в httpOnly cookie и отсюда не виден — в этом и смысл SEC-002.
// Виден только признак CSRF: его ставит сервер рядом с сессией, а мы перекладываем
// его в заголовок изменяющих запросов, доказывая, что запрос сделал сам портал.
const CSRF_COOKIE = 'cp_csrf'
const CSRF_HEADER = 'X-CSRF-Token'
const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS']

function csrfToken() {
  const match = document.cookie.match(new RegExp(`(?:^|; )${CSRF_COOKIE}=([^;]*)`))
  return match ? decodeURIComponent(match[1]) : ''
}

function authHeaders(method = 'GET') {
  const csrf = csrfToken()
  return SAFE_METHODS.includes(String(method).toUpperCase()) || !csrf ? {} : { [CSRF_HEADER]: csrf }
}

/**
 * Единственный способ обратиться к API с учётными данными. Cookie отправляет браузер,
 * поэтому заголовка с токеном больше нет; наша забота — признак CSRF.
 */
function authFetch(url, options = {}) {
  return fetch(url, {
    credentials: 'include',
    ...options,
    headers: {
      ...authHeaders(options.method),
      ...options.headers,
    },
  })
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
  group_id: 'Группа',
  hired_at: 'Дата приема',
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
  enrollment_order_number: 'Приказ о зачислении',
  enrollment_order_date: 'Дата приказа о зачислении',
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
  // Ответ ограничителя приходит от Laravel по-английски, а пользователь читает
  // именно его — при неудачном входе чаще всего.
  if (text === 'Too Many Attempts.' || text === 'Too Many Requests') {
    return 'Слишком много попыток. Подождите минуту и попробуйте снова.'
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
  const isFormData = options.body instanceof FormData

  const response = await authFetch(`${API_BASE_URL}${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
      ...options.headers,
    },
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

  authFetch,

  /**
   * Есть ли сессия — судим по читаемому признаку CSRF: сам токен из JavaScript
   * не виден. Это не проверка прав, а признак «стоит ли пробовать»: окончательный
   * ответ всё равно даёт сервер.
   */
  hasSession() {
    return Boolean(csrfToken())
  },

  /**
   * Забыть сессию на стороне браузера, не дожидаясь сервера. Нужно на 401: httpOnly
   * cookie отсюда не стереть, но признак убрать можно, и интерфейс сразу перестанет
   * считать человека вошедшим. Настоящее снятие делает выход на сервере.
   */
  clearSession() {
    document.cookie = `${CSRF_COOKIE}=; Path=/; Max-Age=0; SameSite=Strict`
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

  // Тело у DELETE необязательное, но иногда нужно: отвязка способа входа требует
  // подтверждения текущим паролем, а класть пароль в адрес нельзя — он попадёт в логи.
  async delete(resource, id, data = null) {
    return request(`/${resource}/${id}`, {
      method: 'DELETE',
      ...(data ? { body: JSON.stringify(data) } : {}),
    })
  },

  async upload(path, formData) {
    return request(path, {
      method: 'POST',
      body: formData,
    })
  },

  async postDownload(path, data) {
    const response = await authFetch(`${API_BASE_URL}${path}`, {
      method: 'POST',
      headers: {
        Accept: 'text/csv',
        'Content-Type': 'application/json',
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
    const response = await authFetch(`${API_BASE_URL}${path}`, {
      headers: {
        Accept: 'text/csv',
      },
    })

    if (!response.ok) {
      const payload = await response.json().catch(() => ({}))
      throw new Error(humanizeApiMessage(payload.message) || 'Файл не удалось скачать')
    }

    return response.blob()
  },
}
