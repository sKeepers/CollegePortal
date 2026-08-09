# Официальные источники ФИС API

Задача: FIS-API-001. **Закрыта 09.08.2026: спецификация есть, схема подключена.**

## Где лежит спецификация

В самом репозитории, с 14.07.2026:

```text
docs/external-services/ФИС ГИА и Приема/
├── XSD схема метода импорта Сервиса автоматизированного взаимодействия.xsd
├── Спецификация сервиса автоматизированного взаимодействия с информационными системами.pdf
└── Функциональные изменения ФИС ГИА и Приема.pdf
```

Версия — **4.9 от 15.06.2026**. Скачивать заново не нужно.

Рабочая копия XSD, которую читает приложение:

```text
backend/resources/fis/gia-priem/4.9/import-package.xsd
backend/resources/fis/gia-priem/4.9/manifest.json
```

Копия нужна потому, что контейнер бэкенда монтирует только каталог `backend/` и до `docs/` не дотягивается. Копия обязана совпадать с оригиналом байт в байт:

```bash
bash scripts/fis/verify-official-xsd-copy.sh
```

Чтобы git не переписал переводы строк и копия не разошлась с оригиналом, в `backend/.gitattributes` для `resources/fis/**` выключена нормализация (`-text`).

## Изменения версии 4.9

- из `Campaign` удалён `YearEnd`;
- из `Application` удалён `NoSnilsComment`; перечень причин отсутствия СНИЛС изменён, добавлена «На уточнении (до внесения в приказ)»;
- в `ApplicationDocument` и `DocumentReason` добавлен `RequestTargetDocuments` — заявки на заключение договора о целевом обучении;
- в заявление, включённое в приказ, добавлен `BenefitDocumentUID`;
- добавлен раздел проверки сведений о результатах ОГЭ.

## Прежний путь загрузки

Ранее ожидалось, что материалы попадут в private storage `backend/storage/app/private/fis-specs/4.9/` через `scripts/fis/download-official-specs.sh` и `scripts/fis/build-spec-manifest.sh`. С DEV сайт `priem.rustest.ru` недоступен: проверка TLS CA не проходит, а `-k` отдаёт `Forbidden`. Скрипты оставлены на случай обновления спецификации через разрешённый АРМ; по умолчанию приложение читает файл из `resources` и в private storage не заглядывает.

## Проверка настройки

```bash
php artisan fis:spec-info
```

Ожидается `schema_version: 4.9`, `xsd_loaded: true` и манифест со статусом `bundled`.
