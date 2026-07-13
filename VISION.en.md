# CollegePortal Vision

## Mission

CollegePortal exists to give the college one coherent digital workspace for educational, administrative and security processes without scattering data across spreadsheets, legacy pages and isolated services.

The system should help employees work with reliable data, clear responsibilities and traceable actions while protecting personal data by default.

## Users

Primary users:

- director and deputies;
- study office;
- admissions office;
- teachers;
- students;
- HR staff;
- access gate/security staff;
- system administrators.

## Core Business Processes

- applicant registration, document tracking and enrollment;
- student/group/teacher directory management;
- curricula and teaching load planning;
- schedule creation and replacements;
- electronic journal, attendance and grades;
- QR passes and access events;
- HR absence calendar and replacements;
- graduation and diploma preparation;
- FRDO/FIS data quality preparation;
- UAT, audit and operational control.

## Modularity And Safety Principles

CollegePortal is built as a modular platform. Each domain owns its data and services, while shared foundations provide identity, reference data, settings, RBAC and audit.

Security principles:

- permission checks belong on the API, not only in the UI;
- personal data is minimized in previews, logs and exports;
- all sensitive runtime data stays outside Git;
- destructive actions require preview, confirmation and audit;
- integrations are introduced through official APIs and documented contracts.

## Person Lifecycle

One `Person` can have several profiles over time or simultaneously:

Applicant -> Student -> Graduate

and independently:

Teacher / Employee / User / Digital Identity

This allows the platform to preserve history without duplicating identity data or forcing risky automatic merges.

## Employee Lifecycle

The HR domain tracks employees, departments, positions, assignments, status periods, absences and teacher replacements. It is the foundation for future HR documents, orders, work time accounting and notifications.

## Workflow Engine Direction

Future workflows should support preview, approval, execution and audit for operations such as enrollment, replacements, document verification, graduation preparation and external reporting packages.

## Notifications

The notification model should support internal notifications first, then email and approved messaging channels such as Telegram or MAX where policy permits. Notifications must not leak sensitive personal data.

## Identity Providers

Future external authentication must use official identity mechanisms only: LDAP/Active Directory, SAML/OIDC or another approved provider. The architecture should not depend on scraping, password sharing or unofficial APIs.

## Roadmap To 1.0

- 0.8: private release candidate and UAT readiness.
- 0.9: pilot real data import, operational hardening and workflow polish.
- 1.0: production readiness, security review, backup drills, trusted TLS and support process.
