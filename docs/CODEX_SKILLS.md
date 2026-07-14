# CollegePortal Skills

Repo-scoped skills находятся в `.agents/skills/` и применяются только в контексте CollegePortal.

| Skill | Когда использовать |
| --- | --- |
| `$collegeportal-feature` | новая законченная функция от inventory до PR |
| `$collegeportal-bugfix` | воспроизводимый дефект и regression test |
| `$collegeportal-uat` | ролевой browser UAT с обезличенными evidence |
| `$collegeportal-release` | version, archive, checksum, install/update/rollback |
| `$collegeportal-infrastructure` | environment, SSH, Docker, Gateway, TLS, installer |
| `$collegeportal-security-review` | read-only review RBAC, ПДн, tokens, uploads, secrets |

Примеры:

```text
Используй $collegeportal-feature для новой страницы учебного процесса.
Используй $collegeportal-bugfix для воспроизводимой ошибки журнала.
Используй $collegeportal-uat для проверки ролей на мобильном viewport.
Используй $collegeportal-security-review для read-only review private files.
```

Каждый skill содержит разрешенные действия, stop-gates и формат отчета. Skill не расширяет scope задачи и не дает разрешения на PROD/UAT/main.
