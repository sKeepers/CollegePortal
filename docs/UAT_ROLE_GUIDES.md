# UAT Role Guides

These guides describe how staff should test CollegePortal during closed role-based UAT. Do not publish real passwords in this document.

## Director

1. Open `/login` and sign in with the director UAT account. Success: `/dashboard` opens with executive widgets. Typical issue: wrong role dashboard.
2. Open `/dashboard`. Check KPI, access/attendance, FRDO/FIS warnings and audit summary. Success: cards load without technical errors.
3. Open `/students` and `/teachers`. Check that lists and right workspaces are readable. Success: create/edit/delete buttons are absent.
4. Open `/schedule` and `/attendance`. Check filters and details. Success: management data is visible in read-only mode.
5. Open `/admin/audit`. Check event list and card. Success: audit is visible, no mutation controls are available.
6. Record any contradiction or missing number through `Сообщить о проблеме`.

## Study Office / Deputy Detailed Scenario

1. `/admin/settings`: verify `current_academic_year` and `current_semester`. Fill only test values. Success: settings match the UAT period. Typical error: semester not aligned with schedule dates. Before next step: save and reload page.
2. `/admin/reference`: verify education forms, funding forms, lesson types, workload types and control types. Success: required catalogs are active. Typical error: inactive item missing in dropdowns. Before next step: check no duplicate names.
3. `/teachers`: create or verify teachers. Fill full name, status, department and contacts if available. Success: teacher appears in filters. Typical error: duplicate teacher by full name. Before next step: open teacher workspace.
4. `/subjects`: create disciplines. Fill name and code. Success: subject appears in curriculum and schedule filters. Typical error: unclear code. Before next step: export/check list if needed.
5. `/curricula`: create or verify curriculum. Fill specialty, qualification, intake year and status. Success: curriculum card opens.
6. `/curricula`: open semesters and disciplines. Fill semester, discipline, lecture/practice/lab/independent hours and control type. Success: summary hours match plan. Typical error: total hours mismatch. Before next step: check summary tab.
7. `/groups`: create groups. Fill name, course, year start and specialty. Success: group appears in students and schedule filters.
8. `/groups` or `/curricula`: link group to active curriculum. Success: group uses one active curriculum without copied discipline rows. Before next step: open curriculum summary for the group.
9. `/teaching-load`: press `Сформировать из учебного плана`. Select group and academic year. Success: preview shows rows without duplicates.
10. `/teaching-load`: apply generation. Success: generated rows have `curriculum_engine` source and planned hours.
11. `/teaching-load`: assign teachers manually or in bulk. Success: assigned/unassigned/overassigned hours are recalculated. Typical error: teacher outside discipline profile warning.
12. `/teaching-load`: check coverage. Success: no unexplained negative or overassigned values.
13. `/schedule`: open visual week editor. Create a lesson or apply a template. Success: preview works before apply.
14. `/schedule`: check conflicts. Success: teacher/group/classroom conflicts are clear and reproducible.
15. `/schedule`: check load coverage. Success: schedule hours are reflected against teaching load.
16. `/schedule`: open a lesson and press `Открыть журнал`. Success: `/journal` opens the selected lesson.
17. `/journal`: switch to `Контроль журналов`. Check not opened, not filled, completed, signed and reopened lessons. Success: filters return expected sets.
18. Use `/admin/uat` to mark each scenario and attach screenshots for failed/blocked cases.

## Admissions

1. `/admin/import`: choose FIS admissions source and upload XLS/XLSX. Success: analyze recognizes the file.
2. Run dry-run. Success: no database changes, errors are row-specific.
3. `/admissions`: open an applicant card. Success: contacts, program, status and events are visible.
4. Check documents. Success: completeness chip and received/verified states are understandable.
5. Run a safe bulk preview. Success: preview explains scope, skipped rows and errors.
6. Check filters by status, program, document status and submission date.

## Teacher

1. Sign in as teacher and open `/dashboard`. Success: only own lessons and journal counters are shown.
2. Open `/schedule` and select a lesson. Success: `Открыть журнал` opens own journal lesson.
3. `/journal`: fill topic, homework, due date and comment. Success: save does not reload page.
4. Mark attendance manually and with access-gate suggestion preview. Success: preview does not mutate until applied.
5. Enter grades and comments. Success: values save and duplicate warnings are understandable.
6. Upload a lesson material. Success: file downloads through authorized API.
7. Complete and sign the lesson. Success: signed lesson becomes read-only.

## Security

1. `/access/gate`: scan active QR. Success: allowed result with direction in/out.
2. Scan revoked QR. Success: denied result with reason.
3. Scan unknown QR. Success: denied without exposing personal data.
4. `/access/mobile-scanner`: verify camera mode on HTTPS phone browser.
5. `/access/reports`: filter events and export CSV.

## Student

1. `/m/student`: open mobile cabinet at 390-430px width. Success: own data only.
2. `/m/student/pass`: open QR pass. Success: QR readable and contains no personal data.
3. `/journal`: verify only allowed journal/grade data. Success: admin sections are forbidden.
4. Try `/admin/uat` or `/admin/users`. Success: 403 or forbidden route.

## Admin

1. `/admin/users`: verify UAT accounts exist. Success: logins visible, passwords never shown.
2. `/admin/roles` and `/admin/permissions`: verify role matrix.
3. `/admin/settings`, `/admin/reference`, `/admin/import`: verify core administration.
4. `/admin/audit`: verify UAT and feedback events are logged.
5. `/admin/uat`: create test runs, export results and triage feedback.
