---
name: collegeportal-bugfix
description: Диагностировать и исправлять воспроизводимые дефекты CollegePortal с regression coverage. Использовать для ошибок, регрессий, нестабильных тестов и расхождения expected/actual; не использовать для новой функции или маскировки симптома.
---

# Workflow bugfix

1. Воспроизвести проблему и зафиксировать environment, шаги, expected, actual, логи и scope.
2. Проследить execution path до root cause; проверить данные, RBAC, cache, timezone, ordering и environment differences.
3. Добавить детерминированный regression test, который падает до исправления по правильной причине.
4. Сделать минимальное исправление без ослабления validation, RBAC или тестовых assertions.
5. Проверить основной сценарий, соседние роли/состояния и отсутствие утечки данных.
6. Запустить targeted tests, полный suite, build, smoke, diff check и secret scan.
7. Документировать причину, почему дефект возник, и доказательство исправления.

## Stop-gates

Остановиться, если дефект не воспроизводится и нет достаточных доказательств, исправление требует breaking change/миграции с потерей данных, тест можно сделать green только через skip/sleep или обнаружен секрет.

## Отчет

Указать root cause, regression test, файлы, проверки, ограничения, commit, PR и CI.
