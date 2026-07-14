# Worktrees для задач Codex

Одна задача выполняется в отдельном worktree. Ветки создаются от актуального `origin/develop`; private files и `.env` не копируются.

## Windows

```powershell
.\scripts\repository\create-task-worktree.ps1 -TaskId CODEX-WORKFLOW-001 -Branch feature/codex-workflow-foundation
.\scripts\repository\remove-task-worktree.ps1 -Path C:\!Projects\CollegePortal-worktrees\codex-workflow-001
```

Рекомендуемый root: `C:\!Projects\CollegePortal-worktrees\<task-id>`.

## Linux DEV

```bash
./scripts/repository/create-task-worktree.sh CODEX-WORKFLOW-001 feature/codex-workflow-foundation
./scripts/repository/remove-task-worktree.sh /srv/college-worktrees/codex-workflow-001
```

Рекомендуемый root: `/srv/college-worktrees/<task-id>`.

Скрипты прекращают работу при dirty state, не используют `reset --hard`, `clean -fd` или force, не удаляют ветку и не удаляют worktree с изменениями. После PR удалять worktree только при clean state. Два write-agent не должны работать в одном worktree.
