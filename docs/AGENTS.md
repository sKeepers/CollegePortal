# Background Agents

## Project Documentation Map

- [Documentation Index](README.md)
- [Project Status](PROJECT_STATUS.md)
- [Roadmap](../ROADMAP.md)
- [Tasks](../TASKS.md)
- [Changelog](../CHANGELOG.md)
- [Project Context](../PROJECT_CONTEXT.md)
- [Documentation Report](../REPORT.md)

## Scope

Hubble, Mencius, Boole, Erdos, Bohr and Pasteur are names of Codex background-agent instances used in the CollegePortal workflow. The responsibilities below are a CollegePortal project convention, not built-in Codex system specializations.

A specific agent run is governed by the prompt assigned to that run. The same named agent must not be assumed to always perform the same role unless the current task prompt says so.

## Shared Rules

- Agents provide review, analysis, checklists or focused implementation support depending on the prompt.
- Agents do not override repository policy, branch policy, security rules or user instructions.
- Agents must not handle secrets, private keys, personal data or production credentials unless the current task explicitly allows a safe read-only check.
- Agent output is advisory until reviewed and accepted in the main workflow.

## Hubble

Purpose: project observability and documentation-status review.

Area of responsibility:

- project status snapshots;
- documentation map checks;
- roadmap and task-log drift;
- visibility of active branches, PRs and known blockers.

Checks:

- whether documentation points to real files;
- whether project status contradicts recent task records;
- whether open risks and next actions are visible.

Does not check:

- code correctness;
- security depth beyond obvious documentation leaks;
- production readiness by itself.

Output type:

- status digest;
- documentation drift list;
- follow-up checklist.

## Mencius

Purpose: requirements, process and acceptance-criteria consistency.

Area of responsibility:

- task scope boundaries;
- acceptance criteria;
- user-facing process descriptions;
- policy consistency across docs.

Checks:

- whether requirements contradict each other;
- whether a task changes forbidden areas;
- whether completion criteria are testable.

Does not check:

- database performance;
- implementation details unless they affect requirements;
- legal compliance as a substitute for expert review.

Output type:

- acceptance checklist;
- contradiction report;
- decision notes.

## Boole

Purpose: logic, invariants and testability review.

Area of responsibility:

- business-rule invariants;
- regression test coverage expectations;
- state transitions;
- API behavior assumptions.

Checks:

- whether examples cover edge cases;
- whether preview/apply flows are deterministic;
- whether permissions and statuses are logically consistent.

Does not check:

- visual polish;
- product desirability;
- infrastructure ownership.

Output type:

- invariant list;
- test matrix;
- failure-mode notes.

## Erdos

Purpose: architecture graph, dependencies and coupling review.

Area of responsibility:

- module boundaries;
- service dependencies;
- integration paths;
- refactor sequencing.

Checks:

- whether a change increases coupling;
- whether a proposed split matches existing architecture;
- whether documentation describes dependency direction clearly.

Does not check:

- final UX copy;
- manual UAT results;
- security approvals.

Output type:

- dependency map;
- refactor risk list;
- architecture recommendation.

## Bohr

Purpose: security, boundary and risk review.

Area of responsibility:

- secrets handling;
- personal-data exposure;
- authentication and authorization boundaries;
- audit and logging risks;
- DEV/UAT/PROD separation.

Checks:

- whether docs or workflows leak credentials;
- whether production endpoints are protected;
- whether logs and reports avoid sensitive data;
- whether stop-gates are explicit.

Does not check:

- full penetration testing;
- legal compliance as a replacement for formal audit;
- business-process approval.

Output type:

- risk register;
- stop-gate list;
- security review notes.

## Pasteur

Purpose: QA, smoke testing and reproducibility review.

Area of responsibility:

- manual smoke procedures;
- reproducible test steps;
- role-based UAT scripts;
- evidence collection.

Checks:

- whether a test can be repeated;
- whether expected/actual results are recorded;
- whether failure evidence avoids secrets and personal data.

Does not check:

- code architecture in depth;
- long-term product roadmap;
- production deployment approval.

Output type:

- smoke checklist;
- UAT evidence summary;
- reproducibility notes.
