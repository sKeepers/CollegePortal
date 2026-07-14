# Профессиональный workflow Codex

Codex работает в CollegePortal как контролируемый engineering worker: одна задача, одна ветка, один write-worker и отдельный worktree. Canonical repository — GitHub, интеграционная ветка — `develop`.

## Последовательность

1. Прочитать `AGENTS.md`, `PROJECT_CONTEXT.md`, `ROADMAP.md`, `TASKS.md` и локальный `AGENTS.md` модуля.
2. Проверить environment по `docs/ENVIRONMENTS.md`, remote, branch, HEAD и clean state.
3. Создать task worktree от актуального `origin/develop`.
4. Исследовать существующий execution path; для read-heavy анализа использовать project agents.
5. Сформировать короткий план, выполнить минимальные изменения и сохранить обратную совместимость.
6. Выполнить migrations, targeted/full tests, build, route/API smoke и browser UAT по риску задачи.
7. Выполнить `git diff --check`, forbidden-file и secret scan.
8. Обновить документацию, создать commit/push/PR в `develop` и дождаться green CI.

`main`, UAT и PROD изменяются только по отдельному разрешению. Private files из других worktree не копируются.

## Stop-gates

Работа прекращается без фиктивного commit, если:

- `origin/develop` не синхронизирован или worktree нельзя создать безопасно;
- текущие изменения конфликтуют с другой feature-веткой;
- тесты, build, YAML/TOML/shell validation или CI остаются красными;
- Playwright требует реальные credentials, которых нет в secret store;
- найден секрет, ПДн или private storage file;
- необходимы UAT/PROD/main, destructive Git или breaking change без разрешения.

## Parallel work

Subagents подходят для независимого read-only анализа. Не запускать два write-agent в одном worktree и не давать subagent параллельно редактировать те же файлы. Основной worker принимает отчеты и единолично пишет код.

## Итоговый отчет

Указывать environment, branch, base/final commits, root cause или цель, файлы, migrations, tests/assertions, frontend build, browser UAT, security checks, ограничения, Issue/PR, CI и commit hash.
