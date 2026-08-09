# Исходящие официальные пакеты ФИС

## Назначение

Outbound-контур отделён от существующего импорта ФИС XLS/XLSX в CollegePortal.

- Inbound: `/admin/import`, загрузка экспорта ФИС в CollegePortal.
- Outbound: `/api/fis/outbound/*`, подготовка, XSD-валидация и тестовая отправка пакетов из CollegePortal в ФИС.

## Схема

Официальная спецификация — **4.9 от 15.06.2026**, метод импорта данных. Соответствие полей описано в [FIS_DATA_MAPPING.md](FIS_DATA_MAPPING.md).

- Оригиналы: [docs/external-services/ФИС ГИА и Приема/](external-services/ФИС%20ГИА%20и%20Приема/).
- Рабочая копия XSD и манифест: `backend/resources/fis/gia-priem/4.9/`. Копия нужна потому, что контейнер бэкенда видит только каталог `backend/`.
- Совпадение копии с оригиналом и с манифестом: `bash scripts/fis/verify-official-xsd-copy.sh`.

Портал собирает элемент `PackageData` без блока `AuthData`: учётные данные ФИС остаются на шлюзовом узле. `PackageData` объявлен в схеме глобальным элементом, поэтому такой документ проверяется по официальной XSD сам по себе.

### Слой совместимости с libxml

В официальном файле семь ограничений `xs:pattern` записаны в синтаксисе .NET и используют опережающую проверку `(?!\s*$)`. В регулярных выражениях XSD 1.0 такой конструкции нет, поэтому **libxml2 отказывается компилировать схему целиком** — не отдельное поле, а весь файл (проверено на libxml 2.9.14, ошибка «Invalid Schema»).

`App\Services\FisIntegration\Xml\FisXsdSchema` подменяет эти шаблоны равнозначным выражением XSD 1.0 `\s*\S[\s\S]*` в памяти. Официальный файл не изменяется. Если ФЦТ добавит новый шаблон с опережающей проверкой, класс скажет об этом прямо, а не оставит невнятное «Invalid Schema». Подстановки попадают в событие `validated` пакета.

## Таблицы

- `fis_outbound_packages` (в том числе `admission_year` — год приёмной кампании для пакета заявлений);
- `fis_outbound_package_events`;
- `fis_external_mappings`.

## Статусы

`draft`, `generated`, `validation_failed`, `validated`, `queued`, `sending`, `sent`, `accepted`, `processing`, `completed`, `rejected`, `failed`, `cancelled`.

## Типы пакетов

- `institution-programs` — образовательные программы;
- `applications` — заявления поступающих за `admission_year`.

Пакет типа `gia` отклоняется с объяснением: раздела для результатов ГИА колледжа в методе импорта нет.

## API

- `GET /api/fis/outbound/spec-info` — версия схемы, контрольная сумма XSD, признак загруженной официальной схемы, список поддержанных типов
- `GET /api/fis/outbound/packages`
- `POST /api/fis/outbound/packages`
- `GET /api/fis/outbound/packages/{id}`
- `POST /api/fis/outbound/packages/{id}/generate`
- `POST /api/fis/outbound/packages/{id}/validate`
- `POST /api/fis/outbound/packages/{id}/send-preview`
- `POST /api/fis/outbound/packages/{id}/send`
- `POST /api/fis/outbound/packages/{id}/refresh-status`
- `POST /api/fis/outbound/packages/{id}/cancel`
- `GET /api/fis/outbound/packages/{id}/events`
- `GET /api/fis/outbound/packages/{id}/download`

`generate` при нехватке сведений отвечает `409` и полем `blockers` — списком всех причин сразу, а не первой попавшейся. XML при этом не сохраняется.

## Настройки

| Ключ | По умолчанию | Смысл |
| --- | --- | --- |
| `FIS_API_SCHEMA_VERSION` | `4.9` | версия спецификации; пусто — берётся значение из кода |
| `FIS_API_XSD_PATH` | `backend/resources/fis/gia-priem/4.9/import-package.xsd` | путь к XSD |
| `FIS_API_SPEC_MANIFEST_PATH` | `backend/resources/fis/gia-priem/4.9/manifest.json` | манифест спецификации |
| `FIS_DICT_GENDER_MALE`, `FIS_DICT_GENDER_FEMALE` | пусто | справочник №5 «Пол»; пока не заданы, сборка заявлений отказывает |

Пустое значение в `.env` считается «не задано»: раньше пустая строка перекрывала значение по умолчанию, и схема не находилась.

## Ограничения

- Production send всегда заблокирован (`FIS_API_ALLOW_PRODUCTION_SEND=false`).
- Mock transport доступен для DEV/test без обращения к ФИС.
- Боевой порт ЗКСПД `10.0.3.1:8080` открыт с ViPNet-ПК. Защита держится на `FIS_API_ALLOW_PRODUCTION_SEND=false` и `FIS_GATEWAY_ALLOWED_ENVIRONMENT=test`. Ослаблять их нельзя.

## FIS-GATEWAY-001

Outbound packages can use Gateway diagnostics for safe TEST checks. Real `DoValidate`, `DoImport` and `DoImportApplicationSingle` remain disabled until TEST credentials are installed on the gateway node.
