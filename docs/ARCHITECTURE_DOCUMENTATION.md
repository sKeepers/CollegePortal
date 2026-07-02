# Architecture Documentation

Дата: 2026-06-30

Единое оглавление архитектурной и проектной документации CollegePortal.

## Основные документы

- `PROJECT_CONTEXT.md` — общий контекст проекта для разработчика.
- `TASKS.md` — текущие задачи и правила работы.
- `ROADMAP.md` — общий план развития.
- `README.md` — быстрый старт.

## Архитектура

- `docs/ARCHITECTURE_DECISIONS.md` — ADR и ключевые архитектурные решения.
- `docs/DOMAIN_MODEL.md` — доменная модель CollegePortal Platform.
- `docs/IDENTITY_DOMAIN.md` — архитектура Identity Domain, Person, Digital Identity и Access Control.
- `docs/DIGITAL_PASSES.md` — QR-001, MVP цифровых пропусков и QR-token.
- `docs/GLOBAL_SEARCH.md` — архитектура глобального поиска.
- `docs/DASHBOARD.md` — концепция Dashboard и виджетов.

## UI и дизайн

- `docs/DESIGN_SYSTEM.md` — единая дизайн-система CollegePortal.
- `docs/UI_FOUNDATION.md` — базовые UI-компоненты и правила CRUD/рабочих страниц.
- `docs/LAYOUT_GUIDELINES.md` — правила layout рабочих страниц.
- `docs/RESPONSIVE_WORKSPACE.md` — breakpoints, режимы workspace и адаптивность.

## Инфраструктура и процесс

- `docs/DEV_ENVIRONMENT.md` — DEV/PROD окружения на Ubuntu.
- `docs/DEPLOYMENT.md` — безопасный процесс DEV -> PROD.
- `docs/GIT_WORKFLOW.md` — Git workflow, ветки и формат коммитов.
- `docs/CODEX_WORKFLOW.md` — порядок работы Codex и Git checkpoint после задачи.

## Принципы терминологии

- `PROD` — рабочий стенд `/home/andale/college_portal`.
- `DEV` — разработческая копия `/srv/college-dev`.
- `Design System` — визуальные правила верхнего уровня.
- `UI Foundation` — реализация дизайн-системы через переиспользуемые компоненты.
- `Layout Guidelines` — правила пространственной раскладки.
- `Responsive Workspace` — адаптация под viewport и режимы workspace.


## Доменные термины

- `Person` — физическое лицо, центральная сущность Identity Domain.
- `Digital Identity` — цифровая идентичность человека: QR, мобильный QR, печатный пропуск, будущие RFID/NFC и Face ID.
- `Access Event` — событие входа, выхода или отказа доступа.
- `Access Device` — устройство или рабочее место проходной.
- `Credential` — учетные данные для входа в приложение или внешнюю систему.
