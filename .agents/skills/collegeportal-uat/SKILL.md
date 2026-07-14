---
name: collegeportal-uat
description: Проводить ролевое пользовательское тестирование CollegePortal в браузере с обезличенными доказательствами. Использовать для UAT-сценариев, responsive-проверок и фиксации feedback; не использовать для деплоя или автоматического исправления найденных дефектов без отдельного задания.
---

# Workflow UAT

1. Зафиксировать environment, build/version, роль, сценарий, preconditions и ожидаемый результат.
2. Использовать разрешенные тестовые учетные записи только из environment variables или secret store.
3. Проверить desktop `1366x768`, `1920x1080` и mobile `390x844`, если сценарий адаптивный.
4. Собирать console/network errors, screenshots и traces без ПДн, токенов и содержимого private files.
5. Для каждого шага ставить `PASS`, `FAIL` или `BLOCKED`; описывать фактический результат и severity.
6. Проверять role permissions, forbidden state, loading, empty и error states.
7. Экспортировать краткий обезличенный отчет и создать feedback/Issue только по разрешению задачи.

## Stop-gates

Остановиться при запросе реальных credentials, риске изменения PROD/UAT данных, невозможности обезличить доказательства или отсутствии требуемой роли/environment.

## Отчет

Указать build, роль, viewport, pass/fail/blocked, evidence paths, findings, ограничения и следующий шаг.
