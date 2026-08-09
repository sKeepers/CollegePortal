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

### Fixed

- The audit log records who was at the other end again. Behind a reverse proxy the portal read the proxy's address as the caller's, so three DEV entries in four said `172.18.0.4` and the login limiter counted the whole portal as one address. The forwarded address is now believed — but only from the network the proxy actually sits in, not from the college LAN, whose clients reach the portal directly and could otherwise write any address they liked into the audit log. Which addresses to trust is a setting, `TRUSTED_PROXIES`.
- A graduate's photograph can be set by the person whose job it is. The permission table said `alumni` where the controller says `graduates`, the two never met, and the fall-through handed the route to whoever holds `reference.manage` — so the study office, holding «Выпуск: ведение», was refused on its own card. Rather than only mending the row, the table moved out of the method it was buried in and a test now walks every route in the group and fails on the first one missing from it: the next such route is caught by the build instead of by a user. Filling it in also gave the routes that had been living on the fall-through their proper permissions — teaching load generation, curriculum subjects, admissions documents.
- The employee export carries the date of birth and the SNILS. The import has always accepted and checked both; the template omitted them, and the export takes its columns from the template. The file therefore looked complete without being so — a spreadsheet saved «to have one» left those two fields nowhere but inside a database dump. Neither becomes mandatory, and columns are matched by name, so files written to the old template still load.

- Five people mistyping their password no longer shut everybody else out. The limiter counted attempts by IP address and email, but the login form has never sent an email field — it sends `login` — so the key collapsed to the address on its own and the five attempts a minute were shared by everyone behind it: the sixth person to sign in, with the right password, was refused. The same slip meant guessing at one account's password was never limited at all, only the address it came from. There are two counters now, because they guard against different things: a strict one per account, which is what actually stops password guessing, and a generous one per address, which stops one machine sweeping through accounts without punishing a college that leaves through a single gateway. Either one alone gives up something. The four ways of writing a phone number share a counter as well — they already found the same person, so counting them apart handed four times the attempts away for nothing. Which spellings mean the same login is now decided in one place rather than restated by the limiter and the login screen separately.

- A spreadsheet that has been through Excel loads back in. Curricula, teaching load and exams were read straight out of the file without stripping the byte order mark Excel writes when it saves as «CSV UTF-8», and that mark lands on the first heading — which is `id`, the column telling an update from an insert. Losing it, the import reported success and created a fresh record for every line: a teaching load exported as thirty rows came back as thirty new loads. Curricula with a code were spared only by a unique index, which turned the same file into a page of raw SQL errors instead. The four imports written by hand in their controllers now read through one place that strips the mark, accepts a comma as well as a semicolon, and tolerates a stray trailing separator rather than failing with a five hundred.
- Every export opens in Excel with its Russian text intact. Nine of the thirteen registers wrote the byte order mark and four did not, so curricula, teaching load, exams and the gate report arrived as mojibake — and a person looking at mojibake re-saves the file, which is how the mark got into the import in the first place. The two halves of that seam were one defect. Writing a CSV is now done in a single place that settles the mark, the separator and the content type once; the journal and UAT exports, which had been quietly using a comma, join the rest on a semicolon.

### Added

- Specialties and education programmes have screens. Both registers had a full API — list, create, change, delete, CSV in and out — and nothing in the portal that reached it: the only forms for them lived in the old interface, which no menu item and no link leads to. So a new specialty could not be entered at all except through the database, and without a programme there is no group, without a group no students, and without a specialty no FRDO package. The screens follow the registers already there: filters that survive a page reload, a card showing what hangs off the record, CSV either way. A programme card counts its groups; a specialty card counts its programmes and says plainly when it has none, because that is the state that blocks everything downstream. A test pins the condition the screens depend on — the role shown the menu item can reach every request they make.

### Fixed

- Creating a specialty or a subject without a code no longer fails with a server error. The code may be omitted — the portal makes one up — but the code that made it up read a field that was not there when nothing was sent. The new screens send an empty code rather than no code, so they never hit it; anything else calling the API did.

## 0.8.0-rc7 - Private Release Candidate

### Added

- Buildings and access points are a reference book, and every scan is bound to the point it came through. Scanners keep sending the point as the string somebody typed into them at installation — no firmware is going to be reflashed for this — so the string is matched against the reference by name or code, ignoring case and stray spacing. A point that is not in the reference still records the pass; it just lands in a separate group rather than being lost.
- Employees can be exported to CSV, like every other register. The columns are the employee import template, read from the import handler itself, so the file loads straight back through Universal Import; the account column is exported empty so a re-import does not reissue accounts.

### Fixed

- A CSV saved by Excel no longer loses its first column. The importer stripped the byte order mark after the header row was parsed, which is too late: the mark sits in front of the opening quote, so the first field was not read as quoted and the heading came back with quote marks attached, matched no alias, and the column was dropped without a word. Any file whose first heading contains a space was affected.
- An account can be created and its password reset from the person's own card — student, teacher or employee — instead of going to the users section and finding the same person a second time. The new password is shown once and the confirmation says, before the button is pressed rather than after, that the current password stops working immediately and the new one cannot be recovered. As with the group issue, it is stored only as a hash and kept out of the audit log.
- The pass is called the same thing everywhere. The student and teacher cards said «QR-пропуск», the employee card said «Выпустить пропуск», and the dialog behind them said «цифровой пропуск», so three names pointed at one object.
- The access report answers the questions it is actually asked: a day, a week or a month by one button instead of two dates, employees as a filter alongside students and teachers, and «только опоздавшие». Lateness is read from the existing attendance analysis rather than computed a second time — but from the per-day count, not the aggregate status, because the aggregate ranks an unclosed entry above a late arrival and would have dropped exactly the person being looked for. Employees are not covered by it, and the toggle says so instead of quietly returning nothing: their lateness depends on a work schedule that is not yet wired to a threshold. A name in the report opens that person's card rather than a search for their surname, so namesakes no longer lead somewhere else.
- The access export is worth opening in a spreadsheet: date and time are separate columns so a period can be pivoted by day, and the building, the access point, and the group or department are named.
- Accounts can be issued to a whole group at once from the students screen. The password is shown exactly once, on the screen that reports the result: it is stored only as a hash, it is deliberately kept out of the audit log, and there is no way to read it back — a lost card means a reset, and the screen says so before the operator starts. Cards print three to a row from that same screen and the list downloads as CSV, both built from the single response that carried the passwords, so nothing has to store them to make them printable.
- «Кто сейчас в здании» — a muster report: who is inside, by name, grouped by building. It opens with no filters and no parameters, because during an evacuation nobody is going to configure a query, and it refreshes itself every 30 seconds. Empty buildings stay on the list so that a building reads as checked and empty rather than as missing. People who came through a point outside the reference are listed under their own heading.

### Fixed

- The settings screen of the live portal can be used at all. Every save was refused as needing a separate confirmation, and there was no way to give one: the endpoint demanded a flag that nothing in the portal ever sent, so the guard had been added and the way through it never built. Saving and resetting now ask for confirmation and send it, the reply carries a machine readable field instead of a sentence to match on, and the screen no longer reports settings as saved when they were not.
- The profile filter in «Люди» filters. Choosing a profile changed nothing, and neither did clearing it, because the filter bar renders no buttons and emits no events while the page listened for events from it — nothing ever asked the server. The list now applies on choice and has explicit apply and reset controls.
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
