const FORBIDDEN = 403

/**
 * Загрузка справочников экрана.
 *
 * Экран не должен закрываться целиком из-за одного справочника, на который у роли
 * нет права: выпадающий список обязан оказаться пустым, а страница — открыться.
 * Раньше все запросы шли одним `Promise.all`, и единственный 403 отклонял его
 * весь — методист «Учебной части» видел «Не удалось загрузить» в четырёх разделах
 * из шести.
 *
 * Терпимость только к 403. Сеть, 500 и истёкшая сессия по-прежнему считаются
 * отказом загрузки: молчать о них нельзя, иначе пустой список будет означать
 * то «нет прав», то «сервер лежит».
 *
 * @param {Record<string, Promise<unknown>>} requests запросы по ключам
 * @returns {Promise<{payloads: Record<string, unknown>, forbidden: string[]}>}
 */
export async function loadReferences(requests) {
  const keys = Object.keys(requests)
  const settled = await Promise.allSettled(keys.map((key) => requests[key]))

  const payloads = {}
  const forbidden = []

  settled.forEach((result, index) => {
    const key = keys[index]

    if (result.status === 'fulfilled') {
      payloads[key] = result.value
      return
    }

    if (result.reason?.status === FORBIDDEN) {
      payloads[key] = null
      forbidden.push(key)
      return
    }

    throw result.reason
  })

  return { payloads, forbidden }
}
