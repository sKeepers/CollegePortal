# UAT Execution Guide

## Purpose

UAT-003 prepares CollegePortal for closed role-based testing. Each scenario must be reproducible: role, page, steps, expected result, actual result and screenshot when useful.

## Access

The `/admin/uat` registry is available to `admin`, `director`, `deputy` and `study` through `uat.manage`. Other roles submit feedback through `Сообщить о проблеме`.

## First Test Run

1. Sign in as admin or study office.
2. Open `/admin/uat`.
3. Press `Новый прогон`.
4. Select role and tester UAT account.
5. Open each scenario, perform the steps and set status: `passed`, `failed`, `blocked` or `skipped`.
6. For failed/blocked scenarios, fill actual result and attach screenshot.
7. Press `Завершить тестирование` and write a summary.
8. Export `Результаты CSV` and `Замечания CSV` for review meeting.

## Status Rules

- `not_started`: scenario was not checked yet.
- `passed`: expected result achieved.
- `failed`: product behaved incorrectly.
- `blocked`: scenario cannot be checked because a prerequisite is missing.
- `skipped`: intentionally skipped with comment.

## UAT Accounts

The registry checks whether DEV users exist for standard logins. Passwords are not shown in UI or documentation.

## Evidence

Screenshots are stored in private storage and downloaded only through authorized API. Do not include passports, SNILS, full addresses or other unnecessary personal data in screenshots.

## Exports

- `/api/admin/uat/export/results.csv`: all scenario results.
- `/api/admin/uat/export/feedback.csv`: feedback registry.
- `/api/admin/uat/export/feedback.csv?failed_only=1`: open issues for retest/review.
