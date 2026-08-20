import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '../services/api'

/**
 * Заявки на удаление и корзина.
 *
 * Удаление идёт в два шага: тот, кто ведёт карточку, помечает её и объясняет
 * причину; администратор проверяет и удаляет. Одобрение не стирает запись —
 * она уходит в корзину, откуда её можно вернуть.
 *
 * Стор обслуживает обе стороны: `requestDeletion` зовут карточки студента,
 * преподавателя и сотрудника, остальное — раздел «Корзина».
 */
export const useTrashStore = defineStore('trash', () => {
  const pending = ref([])
  const history = ref([])
  const items = ref([])
  const loading = ref(false)
  const error = ref('')

  const pendingCount = computed(() => pending.value.length)

  /** Подписи видов карточек. Совпадают с ключами `DeletionRequestService::SUBJECTS`. */
  const SUBJECT_LABELS = {
    student: 'Студент',
    teacher: 'Преподаватель',
    employee: 'Сотрудник',
    person: 'Человек',
  }

  function subjectLabel(type) {
    return SUBJECT_LABELS[type] || type
  }

  /**
   * Что уйдёт вместе с карточкой и что этому мешает.
   *
   * Спрашивается перед пометкой: удалять молча нельзя. У человека вместе с
   * карточкой снимаются его профильные карточки, учётные записи и пропуска —
   * это и показывается; записи приёмной комиссии и выпуска, наоборот, удалению
   * мешают, и их портал называет отдельно.
   */
  async function loadDependents(subjectType, subjectId) {
    const payload = await api.list('deletion-requests/dependents', {
      subject_type: subjectType,
      subject_id: subjectId,
    })

    return payload?.data || { cascade: [], blockers: [] }
  }

  /**
   * Пометить карточку на удаление. Причина обязательна и не короче пяти
   * символов — администратору нужно понимать, что не так с карточкой.
   */
  async function requestDeletion(subjectType, subjectId, reason) {
    error.value = ''
    try {
      const payload = await api.create('deletion-requests', {
        subject_type: subjectType,
        subject_id: subjectId,
        reason,
      })
      return payload?.data
    } catch (err) {
      error.value = err.message || 'Не удалось отправить заявку на удаление'
      throw err
    }
  }

  async function loadPending() {
    const payload = await api.list('deletion-requests/pending')
    pending.value = payload?.data || []
    return pending.value
  }

  async function loadHistory() {
    const payload = await api.list('deletion-requests')
    history.value = payload?.data || []
    return history.value
  }

  async function loadTrash() {
    const payload = await api.list('trash')
    items.value = payload?.data || []
    return items.value
  }

  /** Всё, что нужно разделу «Корзина», одним заходом. */
  async function loadAll() {
    loading.value = true
    error.value = ''
    try {
      await Promise.all([loadPending(), loadHistory(), loadTrash()])
    } catch (err) {
      error.value = err.message || 'Не удалось загрузить корзину'
    } finally {
      loading.value = false
    }
  }

  async function approve(request) {
    error.value = ''
    try {
      await api.create(`deletion-requests/${request.id}/approve`, {})
      await loadAll()
    } catch (err) {
      error.value = err.message || 'Не удалось одобрить заявку'
      throw err
    }
  }

  async function reject(request, comment) {
    error.value = ''
    try {
      await api.create(`deletion-requests/${request.id}/reject`, { comment: comment || null })
      await loadAll()
    } catch (err) {
      error.value = err.message || 'Не удалось отклонить заявку'
      throw err
    }
  }

  async function restore(item) {
    error.value = ''
    try {
      await api.create(`trash/${item.type}/${item.id}/restore`, {})
      await loadAll()
    } catch (err) {
      error.value = err.message || 'Не удалось вернуть карточку'
      throw err
    }
  }

  /** Окончательное удаление. Дальше корзины возврата нет. */
  async function purge(item) {
    error.value = ''
    try {
      await api.delete(`trash/${item.type}`, item.id)
      await loadAll()
    } catch (err) {
      error.value = err.message || 'Не удалось очистить карточку'
      throw err
    }
  }

  return {
    pending,
    history,
    items,
    loading,
    error,
    pendingCount,
    subjectLabel,
    loadDependents,
    requestDeletion,
    loadPending,
    loadHistory,
    loadTrash,
    loadAll,
    approve,
    reject,
    restore,
    purge,
  }
})
