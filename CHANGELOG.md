# Changelog

## Project Documentation Map

- [Documentation Index](docs/README.md)
- [Project Status](docs/PROJECT_STATUS.md)
- [Background Agents](docs/AGENTS.md)
- [Roadmap](ROADMAP.md)
- [Tasks](TASKS.md)
- [Changelog](CHANGELOG.md)
- [Project Context](PROJECT_CONTEXT.md)
- [Documentation Report](REPORT.md)

## Unreleased

### Added

- Buildings and access points are a reference book, and every scan is bound to the point it came through. Scanners keep sending the point as the string somebody typed into them at installation — no firmware is going to be reflashed for this — so the string is matched against the reference by name or code, ignoring case and stray spacing. A point that is not in the reference still records the pass; it just lands in a separate group rather than being lost.
- Accounts can be issued to a whole group at once from the students screen. The password is shown exactly once, on the screen that reports the result: it is stored only as a hash, it is deliberately kept out of the audit log, and there is no way to read it back — a lost card means a reset, and the screen says so before the operator starts. Cards print three to a row from that same screen and the list downloads as CSV, both built from the single response that carried the passwords, so nothing has to store them to make them printable.
- «Кто сейчас в здании» — a muster report: who is inside, by name, grouped by building. It opens with no filters and no parameters, because during an evacuation nobody is going to configure a query, and it refreshes itself every 30 seconds. Empty buildings stay on the list so that a building reads as checked and empty rather than as missing. People who came through a point outside the reference are listed under their own heading.

### Fixed

- The number of people in the building is now computed in one place for all three screens that show it. The rule — the last allowed event of the current day per pass, counted as inside when it is an entry — was written out separately in the access summary and in the dashboard KPI, so the two could drift apart while both looked right. The muster list counts from the same service, and a test pins the three to the same number.

## 0.8.0-rc6 - Private Release Candidate

### Added

- The study office is split into two roles. `study` covers timetables, substitutions, teaching load, curricula and exams; `study_records` covers the student body, the journal, attendance and graduation. The obsolete `academic_office` duplicate is deliberately left alone so existing assignments keep working until it is retired.
- A QR pass is issued together with the account on every path that creates one, including employee import. A pass that is still valid is never reissued, so an account holder no longer has to be handled a second time before they can walk through the entrance.

### Fixed

- People entered by import can log in by phone again. The login form normalises a number to `+7…` and `8…`, while the import stored bare digits and built the login out of them, so every imported account was locked out under every written form of its own number. A phone number is now accepted as `+7`, as `8`, or as bare digits with any separators, and is matched against every written form. Generated logins are normalised to `+7XXXXXXXXXX`.
- A generated login for a person without a phone is now their surname with initials in Latin (`ivanov.ds`), transliterated through our own table: the stock one turned Альгашова into `algasova`. An email address no longer serves as a login.
- The account forms can be filled in. Creating an account demanded a numeric profile ID that nobody has to hand; a person is now found by searching their surname, and the name and email are taken from the chosen card instead of being asked for a second time.
- The user card tells a login apart from an email. The service address `@accounts.collegeportal.local` is no longer presented as a person's mail.
- Who is now in the building is counted over the current day. It was counted over every event ever recorded, so somebody who entered yesterday stayed inside forever, and filtering the report to last month showed who had been in the building during that month. The figure no longer depends on the report filters. A test now covers the seam from scan to reports, widgets and attendance.
- The role screen highlights the selected row, the assignment dialog no longer substitutes the first user in the list, and the create form works.
- The four widget sizes are four different sizes: S and M rendered identically, and so did L and XL. Edit mode no longer rearranges widgets after saving.
- Time is shown in 24-hour form regardless of the browser locale.
- A details card shows which row it belongs to and can be resized. The row separator and highlight were added to the employees, HR calendar and permissions screens.
- Page width is the same in every section.
- The sidebar menu scrolls with the mouse wheel from the first paint. It previously refused to scroll until the scrollbar had been dragged once, because a descendant selector asked for a drawer content element inside a drawer content element and matched nothing: QDrawer puts the class on that element itself. The three-row grid the sidebar was written around therefore never applied, and what scrolled was the whole drawer rather than the menu. The menu now uses a plain overflow container, so there is nothing left to measure.
- Employee import can create accounts and set a work schedule again. Both columns existed for students and teachers but were dropped from the employee handler when it was rewritten on top of `HrService`, so an HR operator importing a spreadsheet had to open every imported employee afterwards to set the schedule, and could not request accounts at all. The work schedule accepts the same wording as the employee card, with any dash or spacing, and an unknown value stops the row naming the column instead of being silently ignored.

## 0.8.0-rc5 - Private Release Candidate

### Added

- SEC-004: the release nginx template redirects HTTP to HTTPS and sends security headers — HSTS, CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` and `Permissions-Policy`. TLS is limited to 1.2 and 1.3 with an explicit cipher list, session tickets are off and the nginx version is hidden. `/.well-known/acme-challenge/` stays on plain HTTP so certificate renewal keeps working, and installations with `HTTPS_MODE=http` are left exactly as they were.
- The installer mounts `/opt/college-portal/acme` into nginx as an ACME webroot, so switching to renewal without downtime becomes a host configuration change instead of a code change.
- `check.sh` verifies the HTTP to HTTPS redirect, the ACME challenge path and the presence of the HSTS and CSP headers.

### Fixed

- The mobile scanner no longer tells every user to open the DEV address when the page is not a secure context; it names the host the portal was actually opened from.

## 0.8.0-rc4 - Private Release Candidate

### Fixed

- The release frontend image no longer reports itself as `dev-unknown` in the `development` environment. `npm run build` fires the `prebuild` hook, which regenerates `version.json` from git, but the image has neither git nor build metadata, so it overwrote the values recorded by `scripts/release/build-release.sh`. Found on 0.8.0-rc3 immediately after deployment.

## 0.8.0-rc3 - Private Release Candidate

First trunk release since 0.8.0-rc2. Merges 121 commits of UAT stabilisation that had never reached `develop`, and is the first release verified by CI on PostgreSQL 17 as well as SQLite.

### Added

- CI runs on feature branches and verifies the backend against PostgreSQL 17, not only in-memory SQLite.
- Planning document for login providers, password recovery and the mobile contour: `docs/AUTH_AND_MOBILE_PLAN.md`.

### Fixed

- Gate QR is accepted when a USB HID scanner types the token with a Russian keyboard layout active.
- Employee import no longer creates duplicates when the personnel number is empty.
- A teacher account without a linked Teacher profile sees an empty teaching load instead of a permission error.
- The UAT student is linked to a Person and Student profile, so the student cabinet, personal QR pass and group journal resolve.

## 0.8.0-rc2 - Private Release Candidate

### Added

- Installer lifecycle for Ubuntu Server: install, update, backup, restore, check and uninstall.
- UAT validation on a clean server documented in `docs/INSTALLATION_ACCEPTANCE_TEST.md`.
- Person foundation, Identity domain and digital passes.
- Admissions, FIS import, applicant documents and bulk operations.
- HR employee foundation, absence calendar and teacher replacements.
- Curriculum Engine, Teaching Load Engine and Schedule Engine with visual editor.
- Schedule-linked electronic journal and teacher journal workspace.
- Attendance analytics and access reports.
- RBAC permission matrix, audit log, settings center and reference data platform.
- UAT Center for role-based testing and feedback.

### Changed

- Release archive generation writes correct `version.json` metadata.
- Installer handles Ubuntu Docker Compose package differences.
- Restore resets schema before import to avoid duplicate/relation conflicts.
- Update recreates nginx after service rebuilds and waits for health.


### Documentation

- README.md is now the primary Russian GitHub entrypoint.
- Added README.en.md for future English publication.
- Localized GitHub issue and pull request templates.
- Added Russian security, support and vision materials for private RC/UAT.

### Security

- Private runtime data, credentials, certificates, backups, dumps and release archives are excluded from Git.
- Documentation records personal data handling and repository safety rules.

## Earlier Milestones

See `ROADMAP.md`, `TASKS.md` and module documentation in `docs/` for detailed task history.
