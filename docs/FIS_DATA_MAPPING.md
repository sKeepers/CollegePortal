# Mapping CollegePortal → ФИС ГИА и Приёма

Спецификация: «Сервис автоматизированного взаимодействия с информационными системами образовательных организаций», версия **4.9 от 15.06.2026**. Метод — **импорт данных**.

Исходники лежат в репозитории: [docs/external-services/ФИС ГИА и Приема/](external-services/ФИС%20ГИА%20и%20Приема/). Рабочая копия XSD, которую читает приложение, — `backend/resources/fis/gia-priem/4.9/import-package.xsd`; совпадение с оригиналом проверяет `scripts/fis/verify-official-xsd-copy.sh`.

## Что принимает метод импорта

Корневой элемент схемы — `Root` с двумя частями: `AuthData` (логин, пароль, ИД организации) и `PackageData` с данными. `PackageData` объявлен глобальным элементом, поэтому проверяется по XSD сам по себе.

**Портал собирает только `PackageData`.** Логин и пароль ФИС живут на шлюзовом узле в ЗКСПД и в портал не передаются; блок авторизации добавляет шлюз перед отправкой.

Разделы `PackageData`: `CampaignInfo`, `AdmissionInfo`, `InstitutionAchievements`, `TargetOrganizations`, `InstitutionPrograms`, `Applications`, `Orders`.

**Раздела для результатов ГИА колледжа в методе импорта нет.** «ГИА» в названии системы относится к ЕГЭ и ОГЭ, сведения о которых вносит РЦОИ, а не образовательная организация. Единственное упоминание ГИА в схеме — `GiaDocuments`, то есть справки ГИА, которые абитуриент приложил к заявлению. Пакет ГИА портала (`FisPackage` с `package_type = gia`) остаётся внутренним отчётом; попытка собрать по нему XML отклоняется с этим объяснением.

## Поддержанные типы исходящих пакетов

| Тип | Раздел схемы | Состояние |
| --- | --- | --- |
| `institution-programs` | `InstitutionPrograms` | собирается полностью из данных портала |
| `applications` | `Applications` | собирается при настроенных сопоставлениях, см. ниже |
| `gia`, `gia-results` | — | отклоняется: раздела нет в схеме |
| прочие | — | отклоняется как неизвестный тип |

`CampaignInfo`, `AdmissionInfo` и `Orders` не собираются: приёмных кампаний, контрольных цифр приёма, конкурсов и приказов о зачислении портал как сущности не ведёт. Заводить их «под ФИС» без разбора предметной области нельзя.

## InstitutionPrograms — образовательные программы

| XPath | Обяз. | XSD type | Источник | Преобразование |
| --- | --- | --- | --- | --- |
| `InstitutionProgram/UID` | да | TUID (≤200) | `education_programs.id` | `education-program-{id}` |
| `InstitutionProgram/Name` | да | string 4..200 | `education_programs.name` | как есть |
| `InstitutionProgram/Code` | нет | string ≤10 | `specialties.code` | как есть |

Отбор: `is_active = true`. Если задан `source_entity_type = education_program`, берётся одна программа по `source_entity_id`.

## Applications — заявления поступающих

Источник — foundation-заявления (`applicant_applications.record_type = foundation`) за год пакета, кроме черновиков. Год берётся из `fis_outbound_packages.admission_year`.

| XPath | Обяз. | XSD type | Источник | Преобразование |
| --- | --- | --- | --- | --- |
| `Application/UID` | да | TUID | `applicant_applications.uuid` | иначе `admission-application-{id}` |
| `Application/FromEPGU` | да | boolean | `metadata.from_epgu` | по умолчанию `false` |
| `Application/ApplicationNumber` | да | string ≤50 | `application_number` | как есть |
| `Entrant/UID` | да | TUID | `applicants.uuid` | иначе `person-{id}` |
| `Entrant/LastName` | да | string ≤250 | `people.last_name` | запасной вариант — поле заявления |
| `Entrant/FirstName` | да | string ≤250 | `people.first_name` | запасной вариант — поле заявления |
| `Entrant/MiddleName` | нет | string ≤250 | `people.middle_name` | — |
| `Entrant/GenderID` | да | справочник №5 | `people.gender` | `FIS_DICT_GENDER_MALE` / `FIS_DICT_GENDER_FEMALE` |
| `Entrant/SNILS` | нет | string ≤14 | `people.snils` | уже хранится как `ddd-ddd-ddd dd` |
| `Entrant/EmailOrMailAddress/Email` | нет | string ≤150 | `people.email` | — |
| `Application/RegistrationDate` | да | dateTime | `registered_at`, иначе `submitted_at` | `Y-m-d\TH:i:s` |
| `Application/NeedHostel` | да | boolean | `metadata.need_hostel` | по умолчанию `false` |
| `Application/StatusID` | да | справочник №4 | `status_id` | сопоставление `fis:ApplicationStatusID` |
| `Application/StatusComment` | нет | string ≤4000 | `comment` | — |
| `Application/After11` | нет | boolean | `education_base` | `after_11`/`secondary_general` → `true`, `after_9`/`basic_general` → `false`, иначе элемент не пишется |
| `FinSourceEduForm/CompetitiveGroupUID` | да | TUID | сопоставление программы | `fis:CompetitiveGroupUID` |

### Документ, удостоверяющий личность

`ApplicationDocuments/IdentityDocument` обязателен для каждого заявления. Источник — документ, привязанный к заявлению (`admission_application_documents`), иначе действующий документ человека.

| XPath | Обяз. | Источник |
| --- | --- | --- |
| `UID` | да | `fis_uid`, иначе `identity-document-{id}` |
| `LastName` / `FirstName` / `MiddleName` | нет | `people.*`; поле с цифрой не пишется — XSD запрещает цифры в `TPersonalName` |
| `DocumentSeries` | нет | `series` |
| `DocumentNumber` | да | `number` |
| `SubdivisionCode` | нет | `subdivision_code`, приводится к `ddd-ddd`; иной формат не пишется |
| `DocumentDate` | да | `issue_date` |
| `DocumentOrganization` | нет | `issued_by` |
| `IdentityDocumentTypeID` | да | `fis_identity_document_type_id` (справочник №22) |
| `NationalityTypeID` | да | `fis_nationality_type_id` (справочник №7) |
| `BirthDate` | да | `people.birth_date` |
| `BirthPlace` | нет | `people.place_birth` |
| `ReleaseCountryID` | да | `fis_release_country_id` (справочник №7) |
| `ReleasePlace` | да | `release_place` |

### Документ об образовании

`EduDocuments` необязателен. Элемент выбирается по коду справочника портала, а не по `metadata.fis_type`: один и тот же тип `TSchoolCertificateDocument` схема использует для двух разных элементов.

| Код справочника портала | Элемент схемы |
| --- | --- |
| `basic_general_certificate` | `SchoolCertificateBasicDocument` |
| `secondary_general_certificate` | `SchoolGeneralCertificateDocument` |
| `spo_diploma` | `MiddleEduDiplomaDocument` |
| `npo_diploma` | `BasicDiplomaDocument` |
| `academic_certificate` | `AcademicDiplomaDocument` |
| `foreign_education_document` | `ForeignStateEduDocument` |
| `other_education` | `EduCustomDocument` |

Поля: `UID` ← `fis_uid`, `DocumentSeries` ← `series`, `DocumentNumber` ← `number`, `DocumentDate` ← `issue_date`, `DocumentOrganization` ← `document_organization`, `RegionId` ← `fis_region_id` (справочник №8), `EndYear` ← `graduation_year`, `GPA` ← `average_score`, `OriginalReceivedDate` ← `original_received_at`. У `ForeignStateEduDocument` вместо региона обязательна `CountryID` ← `fis_country_id`. У `EduCustomDocument` обязателен `DocumentTypeNameText` ← название типа документа.

## Правило про справочники

Идентификатор справочника ФИС либо известен из настроенного сопоставления, либо не выдаётся вовсе. Ничего не угадывается: неверный ИД в официальном пакете — это не опечатка, а недостоверные сведения.

Источники значений:

1. Явные колонки `fis_*` у документов (`fis_identity_document_type_id`, `fis_region_id`, …).
2. Таблица `fis_external_mappings` — для элементов справочников портала и для UID конкурсных групп.
3. `config/fis_api.php` → `dictionaries` — там, где в портале нет элемента справочника (пол).

Действующие значения справочников берутся методом получения элементов справочника через шлюз, а не из документации.

## Что делает сборка, если сведений не хватает

Сборка не останавливается на первой находке: собирается весь список причин и возвращается в ответе `409` полем `blockers`, а также записывается в событие пакета `generation_blocked`. XML при этом не сохраняется.

Коды причин: `field_missing`, `field_too_short`, `field_too_long`, `reference_missing`, `no_source_data`, `admission_year_missing`, `person_missing`, `choices_missing`, `competitive_group_missing`, `identity_document_missing`, `education_document_type_unmapped`.

## Конкурсные группы

Конкурсы (`CompetitiveGroups`) создаются в самой ФИС. Портал их не ведёт, поэтому связь «образовательная программа → конкурс» хранится в `fis_external_mappings`:

```
entity_type    = App\Models\EducationProgram
entity_id      = id программы
external_type  = fis:CompetitiveGroupUID
external_id    = UID конкурса в ФИС
environment    = test | production
```

Без этой записи заявление в пакет не попадёт: `CompetitiveGroupUID` обязателен по схеме.
