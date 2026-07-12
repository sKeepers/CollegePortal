# UAT Feedback Process

## Submitting Feedback

Any authenticated UAT participant can press `Сообщить о проблеме` in the topbar. The form automatically fills:

- current page URL;
- current role;
- version;
- build;
- environment.

The tester fills category, severity, title, description, expected behavior and actual behavior. Screenshot is optional.

## Categories

- `error`: functional error.
- `ux`: inconvenient or unclear behavior.
- `suggestion`: improvement proposal.
- `data`: wrong or missing data.
- `access`: permission or role problem.

## Severity

- `critical`: blocks testing or risks data loss/security.
- `high`: blocks an important role workflow.
- `medium`: important but has workaround.
- `low`: minor defect.
- `ux`: usability issue without functional breakage.

## Registry Workflow

Feedback statuses:

- `new`: received.
- `confirmed`: reproduced by admin/study.
- `in_progress`: accepted for correction.
- `fixed`: fixed and ready for retest.
- `rejected`: not a defect or out of scope.
- `retest`: needs user retest.
- `closed`: verified and closed.

## Security

Screenshots are stored in private storage. Direct public URLs are not generated. Feedback metadata must not include auth tokens, passwords, private keys or secrets.

## Review Rhythm

At the end of each UAT day:

1. Export open issues.
2. Group by role and severity.
3. Confirm reproducibility.
4. Move accepted issues to `in_progress`.
5. Mark fixed issues as `retest` before asking users to check again.
