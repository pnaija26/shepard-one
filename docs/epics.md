---
stepsCompleted:
  - step-01-validate-prerequisites
  - step-02-design-epics
  - step-03-create-stories
  - step-04-final-validation
inputDocuments:
  - docs/shepardOne_initial_prd.md
---

# shepardOne - Epic Breakdown

> **UX implementation reference:** Use [UX Design Contract](ux-design-contract.md) and the interactive `/design/prototype` route when designing, implementing, and reviewing user-facing stories.

## Overview

This document provides the complete epic and story breakdown for shepardOne, decomposing the requirements from the PRD, UX Design if it exists, and Architecture requirements into implementable stories.

## Requirements Inventory

### Functional Requirements

FR1: Authorized administrators can configure the church hierarchy, including headquarters, branches, campuses, locations, ministries, departments, service teams, and groups.

FR2: The system can segment operational data by branch while allowing authorized HQ users to access consolidated church-wide data and reporting.

FR3: The system can maintain centralized member identity across branches and record controlled cross-branch member transfers or movements.

FR4: Administrators can define configurable roles and assign permissions by organization, branch, ministry, department, team, group, module, record type, function, and action.

FR5: Users can authenticate through ShepardOne-owned Identity contracts implemented by replaceable Laravel adapters, and privileged users can complete configured multi-factor authentication.

FR6: Members and authorized staff can register member profiles through web, mobile, reception, branch administration, QR code, kiosk, and imported records.

FR7: Authorized users can view, search, update, archive, and manage member profiles containing identity, contact, demographic, church, spiritual, skills, attendance, team, group, and permission-controlled sensitive information.

FR8: Members can update approved self-service profile fields, while changes to configured sensitive fields can be routed for administrative approval.

FR9: The system can assign a unique membership ID and generate a digital membership card containing the member's name, photograph, ID, QR code, branch, and membership status.

FR10: The system can detect potential duplicate member profiles using configurable identity attributes and allow authorized administrators to review and merge duplicates.

FR11: Authorized users can create family or household profiles, define relationships and dependants, associate members without duplicating their data, and view household-level activities and milestones.

FR12: Authorized users can configure and track member lifecycle stages and statuses from visitor or new convert through active membership, service, leadership, transfer, inactivity, death, and archival.

FR13: Authorized users can record first-time and returning visitors, invitation and service details, decisions, prayer needs, membership interest, assigned follow-up officers, and follow-up history.

FR14: The system can execute configurable visitor and new-member onboarding sequences with timed communications, tasks, escalation, class or baptism milestones, group assignment, and service-team interest.

FR15: Authorized users can create and manage church services with type, schedule, venue, branch, ministers, service teams, capacity, registration, attendance targets, and livestream details.

FR16: Authorized users can create and manage events, registrations, RSVPs, optional ticketing, QR admission, volunteers, tasks, materials, notifications, budgets, attendance, and post-event reports.

FR17: Members can register for eligible events through web, mobile, or QR-enabled flows and receive a registration ID, QR code, confirmation, and reminder.

FR18: Authorized users can capture attendance for services, branches, events, departments, teams, groups, and training using manual entry, QR, mobile check-in, member ID, barcode, kiosk, or administrator entry.

FR19: Attendance records can distinguish present, absent, excused, late, online, first-timer, and visitor statuses.

FR20: The system can identify configured attendance exceptions and trigger follow-up tasks, assignments, recorded outcomes, escalations, and case closure.

FR21: Authorized users can create service teams with leadership, membership, skill, staffing, schedule, objectives, reporting, attendance, and approval settings.

FR22: Authorized users can search, bulk assign, transfer, remove, and organize members across teams, roles, sub-teams, shifts, and service responsibilities, subject to church policy.

FR23: Team leaders can create weekly, monthly, event, and shift rosters with duty assignments, member responses, replacement requests, substitutes, notifications, and conflict detection.

FR24: Team leaders can capture independent team and rehearsal attendance and view totals, percentages, trends, reliability, and members requiring follow-up.

FR25: Team leaders can submit configurable electronic reports, attach supporting evidence, route reports for review or return, and contribute approved results to dashboards.

FR26: Authorized administrators can build team-specific report forms without code by configuring supported field types, validation, required status, and attachments.

FR27: Each service team can access a permission-scoped dashboard for membership, attendance, rosters, assignments, reports, tasks, training, events, notifications, performance, and issues.

FR28: The system can maintain volunteer profiles with skills, expertise, availability, preferences, experience, certification, training, service history, teams, and volunteer hours.

FR29: The system can recommend suitable volunteers using skills and availability while allowing authorized users to make the final assignment.

FR30: Authorized users can create and manage cells, fellowships, classes, and other groups, including leaders, members, meetings, attendance, activities, communication, follow-up, reports, and dashboards.

FR31: Members and authorized staff can create welfare requests or cases with beneficiary, category, assessment, priority, documents, recommendations, assistance, value, follow-up, status, and a unique case number.

FR32: Authorized administrators can configure welfare approval levels and monetary thresholds, and the system can route each request through assessment, approval, disbursement, confirmation, follow-up, and closure.

FR33: Authorized users can track welfare approval history, assistance delivery, beneficiaries, outstanding cases, expenditure, and reports while restricting sensitive and financial data.

FR34: Authorized care personnel can create restricted pastoral or member-care cases for visits, counselling, bereavement, family needs, emergencies, prayer, and related follow-up.

FR35: Care cases can be assigned, actioned, scheduled for follow-up, escalated, resolved, and closed with a complete restricted case history.

FR36: Members can submit prayer requests with category, confidentiality scope, priority, date, assignment, and status, and authorized prayer or pastoral teams can process them.

FR37: Authorized users can manage follow-up work for visitors, converts, members, absentees, welfare beneficiaries, prayer requests, care cases, and event participants with due date, contact method, outcome, and next action.

FR38: Users can create, assign, prioritize, attach files to, track, and complete branch- or department-scoped tasks with due dates and standard statuses.

FR39: Authorized administrators can configure reusable workflows containing triggers, conditions, assignments, approvals, rejection, escalation, notifications, deadlines, reminders, and automated actions.

FR40: Authorized administrators can configure automation rules for attendance, birthdays, anniversaries, team assignments, welfare decisions, and other supported domain events without source-code changes.

FR41: The system can send immediate, scheduled, recurring, event-based, workflow-based, and personalized communications through email, SMS, push, in-app notifications, and supported external channels.

FR42: Users can access a notification inbox, filter by supported categories, and mark notifications read, unread, or archived.

FR43: Authorized administrators can create reusable email and SMS templates with validated merge variables for supported communication scenarios.

FR44: The system can detect birthdays and configured anniversaries, select an approved template, generate a personalized message, deliver it through configured channels, notify relevant teams, and log delivery activity.

FR45: Authorized communications staff can design, preview, test, approve, schedule, and send newsletters and view delivery, open, click, bounce, and unsubscribe analytics.

FR46: Authorized users can communicate in moderated church, branch, ministry, team, cell, department, and event spaces using supported message and attachment types, polls, announcements, read status, and search.

FR47: Authorized administrators can publish and manage announcements, verses, news, sermons, articles, testimonies, media, downloads, and event content.

FR48: The system can integrate with an existing church payment platform or approved payment gateway to record permission-controlled giving categories, references, receipts, history, statements, campaigns, and reports.

FR49: Members can access a mobile-first dashboard for profile, household, church affiliation, schedule, attendance, enabled giving, welfare, communications, prayer, care, groups, and team assignments.

FR50: Team leaders can access a mobile-first dashboard for members, availability, attendance, rosters, assignments, tasks, reports, notifications, training, performance, and follow-up.

FR51: Branch administrators can view permission-scoped operational metrics for members, visitors, converts, attendance, teams, volunteers, welfare, care, events, giving, growth, and follow-up.

FR52: HQ leaders can view consolidated, permission-scoped KPIs and comparisons across branches for membership, attendance, engagement, welfare, care, events, giving, and branch performance.

FR53: Authorized users can configure role-specific dashboards from supported KPI, chart, table, map, trend, demographic, and operational widgets.

FR54: Authorized users can run standard membership, attendance, team, welfare, care, communication, operational, and management reports.

FR55: Authorized users can build reports without code by selecting data sources and fields, applying permission-aware filters, grouping, sorting, calculating, previewing, and saving definitions.

FR56: Authorized users can export eligible reports to PDF, Excel, CSV, print, dashboard, or email while preserving access controls and auditability.

FR57: Authorized users can schedule report generation and automatic distribution to permission-checked recipients, with generation and delivery logging.

FR58: Users can upload, download, version, categorize, search, and permission-control member, welfare, training, meeting, policy, form, event, and report documents.

FR59: Authorized users can globally search members, families, branches, teams, groups, events, attendance, welfare, care, reports, and documents, with results filtered by their effective permissions.

FR60: Members can control which eligible profile details appear in the church directory, and authorized users can search the resulting privacy-filtered directory.

FR61: Authorized users can manage courses, classes, schedules, enrolment, attendance, assessments, materials, completion, and certificates for membership, discipleship, leadership, ministry, and volunteer training.

FR62: Members can submit service or event feedback, and the system can route categorized feedback to responsible teams.

FR63: Authorized team members can report, classify, assign, investigate, resolve, review, and close operational incidents.

FR64: Authorized administrators can import existing data from Excel, CSV, databases, and membership systems using mapping, cleansing, duplicate detection, validation, test migration, approval, production migration, and migration reporting.

FR65: The platform can expose documented, versioned REST/JSON APIs with authentication, authorization, API keys or OAuth where applicable, rate limiting, logs, and webhooks.

FR66: The platform can integrate through configurable adapters with approved SMS, email, payment, WhatsApp Business, push, website, livestream, accounting, identity, storage, and productivity services.

FR67: The system can record immutable audit events for authentication, data changes, approvals, exports, reports, sensitive access, and access-control changes with actor, timestamp, context, affected record, and value changes where applicable.

FR68: Authorized administrators can configure church information, branches, organizational units, teams, roles, permissions, categories, statuses, approval levels, workflows, notification rules, templates, reports, widgets, and form fields through the administration interface.

FR69: The hybrid mobile application can be installed for Android and iOS and provide the approved member and team-leader capabilities through the Laravel API.

FR70: Administrators can monitor configured application, API, integration, notification, job, backup, and security events and access operational logs appropriate to their role.

### NonFunctional Requirements

NFR1: The platform must support logically multi-tenant, multi-branch operation with centralized governance and branch-level data isolation.

NFR2: Typical web page loads must complete within 3 seconds under the agreed normal workload.

NFR3: Typical API requests must complete within 2 seconds under the agreed normal workload.

NFR4: Typical global searches must complete within 2 seconds, and typical dashboards within 5 seconds, under the agreed normal workload.

NFR5: Production availability must be at least 99.5%, excluding agreed maintenance windows.

NFR6: The solution must scale from at least 10,000 toward 100,000 or more member records, multiple branches and concurrent users, high-volume attendance, bulk communications, and large reporting workloads based on validated capacity targets.

NFR7: All network traffic must use HTTPS/TLS, and sensitive data and backups must be encrypted at rest using approved cryptographic controls.

NFR8: Password storage, session management, account lockout, input validation, rate limiting, CSRF protection, XSS protection, SQL-injection protection, and secure API authentication must follow current Laravel and OWASP guidance.

NFR9: Multi-factor authentication is mandatory for privileged users and must be implemented through ShepardOne-owned Identity contracts with replaceable Laravel-compatible adapters.

NFR10: Every data access path, including search, exports, reports, dashboards, jobs, and APIs, must enforce effective role and scope permissions.

NFR11: Sensitive welfare, care, prayer, identity, and financial records must use least-privilege access and auditable access controls.

NFR12: The solution must comply with applicable Nigerian data protection requirements and church privacy policy, including consent, notice, minimization, access, correction, export, retention, archival, and restricted-record handling.

NFR13: Audit records must be tamper-resistant, searchable by authorized users, retained according to policy, and include sufficient context for accountability.

NFR14: Automated daily incremental and weekly full backups must be encrypted, monitored, and replicated off-site or to an independently protected location.

NFR15: The disaster-recovery target is RPO no greater than 1 hour and RTO no greater than 4 hours, proven through restoration and disaster-recovery exercises.

NFR16: Production is cloud-primary with documented, repeatable deployment and configuration; on-premises or off-site infrastructure is limited to independently protected recovery targets and named local integrations unless a later architecture decision expands it.

NFR17: The user experience must be responsive, mobile-first, accessible, consistent, church-branded, intuitive, and usable with assistive technology.

NFR18: The web application must support current stable versions of Chrome, Edge, Firefox, and Safari; the hybrid app must support the agreed maintained Android and iOS versions.

NFR19: The platform must use a normalized relational data model with controlled migrations, referential integrity, and an agreed PostgreSQL or MySQL/MariaDB implementation.

NFR20: API contracts must be documented, versioned, backward-compatibility managed, rate-limited, observable, and protected by the same authorization model as the applications.

NFR21: Bulk emails, notifications, scheduled reports, workflows, and other long-running operations must execute asynchronously with retry, failure handling, idempotency where required, and operational visibility.

NFR22: Uploaded documents and media must be malware-checked where feasible, validated by type and size, stored outside the public application path or in protected object storage, and delivered through authorization checks.

NFR23: The project must include unit, functional, integration, regression, security, vulnerability, performance, load, restoration, disaster-recovery, mobile, browser-compatibility, and formal user-acceptance testing appropriate to each release.

NFR24: Source code, database schema, deployment scripts, API documentation, security documentation, administration guides, user guides, training assets, test evidence, migration tools, and recovery procedures must be delivered and maintained.

NFR25: Critical production incidents must receive a response within 1 hour and a target resolution or workaround within 4 hours; high-priority incidents within 4 hours; normal incidents within 1 business day during the agreed support period.

NFR26: The implementation must be modular and configurable so supported operational changes can be made by authorized administrators without source-code changes.

NFR27: Personally identifiable and sensitive data must not be exposed in logs, notifications, analytics, client storage, URLs, or error responses.

NFR28: The hybrid mobile application must handle intermittent connectivity gracefully, preserve user-entered data during recoverable failures, and clearly communicate synchronization state for supported offline-tolerant operations.

### Additional Requirements

- Use Laravel/PHP for the backend application and REST/JSON API.
- Use Vue.js for the web and hybrid mobile user interface; the architecture phase must select and document the hybrid runtime and native bridge, such as Ionic Vue with Capacitor.
- Implement ShepardOne-owned Identity contracts with replaceable Laravel authentication adapters; Fortify and Sanctum are implementation details rather than the domain boundary.
- Before implementation, verify adapter support for invitation-only login, sessions and device credentials, TOTP, recovery, lockout, audit hooks, OIDC federation, Laravel compatibility, and mobile-client flows.
- Implement deny-by-default application authorization and tenant or branch scoping around authenticated principals using the canonical policy interface and database-enforced isolation defined by the architecture spine.
- Define the mobile credential-storage, token refresh, revocation, logout, device registration, and push-notification association design before mobile authentication stories are implemented.
- Create a dedicated architecture artifact before implementation to decide modular boundaries, tenancy model, relational database, cache, queue, search, object storage, deployment topology, observability, API versioning, and integration patterns.
- Create a dedicated UX design contract before implementation to define user journeys, information architecture, design tokens, reusable components, accessibility behavior, responsive layouts, and hybrid-mobile interaction patterns.
- Treat the PRD MVP as the initial product scope: core platform, church operations, service teams, communications, welfare, dashboards, standard reports, and basic custom reporting.
- Treat the PRD Phase 2 and Phase 3 lists as later releases unless product planning explicitly promotes an item into the MVP.
- Use a centralized relational database with explicit branch and organizational scope on tenant-owned records and centrally governed reference data.
- Use queued jobs for communications, workflow automation, report generation, imports, exports, and other high-volume or long-running work.
- Provide environment-specific, repeatable deployment, migration, scheduler, worker, storage, backup, and restoration procedures for the cloud-primary runtime and independently protected recovery targets selected during architecture.
- Establish API and integration contracts for email, SMS, push, payment, WhatsApp, website, livestream, accounting, identity, cloud storage, and productivity providers before provider-specific implementation.
- Establish data classification, retention, consent, encryption, audit, and restricted-record policies before implementing welfare, care, prayer, giving, and directory features.
- Validate expected branches, membership volume, concurrent users, attendance peaks, communication volume, reporting volume, media storage, RPO, and RTO during architecture and capacity planning.
- Include controlled data migration rehearsal, reconciliation, UAT approval, production cutover, rollback, verification, and hypercare in the release plan.
- Obtain formal UAT sign-off from authorized church representatives before production deployment.

### UX Design Requirements

UX-DR1: Create a church-branded design system defining semantic color, typography, spacing, iconography, elevation, motion, focus, and component-state tokens for web and hybrid mobile experiences.

UX-DR2: Define role-based information architecture and navigation for members, team leaders, branch administrators, HQ leaders, welfare officers, care personnel, communications staff, and system administrators.

UX-DR3: Design responsive, mobile-first layouts for the administrative web application and touch-optimized hybrid mobile layouts for member and team-leader workflows.

UX-DR4: Provide accessible keyboard navigation, visible focus, semantic structure, labeled controls, screen-reader announcements, error identification, and contrast that meets the agreed WCAG 2.2 conformance level.

UX-DR5: Define reusable interaction patterns for loading, empty, success, warning, error, offline, synchronization, permission-denied, session-expired, and destructive-confirmation states.

UX-DR6: Design efficient high-volume workflows for member search and registration, attendance check-in, roster creation, welfare assessment, approvals, reporting, and data import.

UX-DR7: Define privacy-aware UI behavior that masks or omits restricted fields and clearly distinguishes public directory data, internal data, financial data, and sensitive care or welfare data.

UX-DR8: Prototype and usability-test the core MVP journeys with representative church users before implementation, recording findings and required design changes.

UX-DR9: Define hybrid-mobile behavior for device permissions, secure login, push notifications, QR scanning, camera or file upload, deep links, app updates, intermittent connectivity, and synchronization feedback.

UX-DR10: Ensure forms use appropriate controls, clear validation, preserved user input, progressive disclosure, and review steps for long or sensitive submissions.

UX-DR11: Ensure dashboards use role-relevant density, clear hierarchy, accessible data visualizations, responsive tables, and drill-down paths without exposing unauthorized aggregates.

UX-DR12: Define communication preference and consent experiences for email, SMS, push, in-app, directory visibility, and optional third-party channels.

### FR Coverage Map

FR1: Story 1.3 - Configure the church hierarchy.
FR2: Story 1.4 - Enforce branch segmentation and HQ consolidation.
FR3: Story 1.5 - Maintain centralized identity across branch movement.
FR4: Story 1.6 - Configure scoped roles and permissions.
FR5: Stories 1.1-1.2 - Authenticate through the existing Laravel package and require privileged MFA.
FR6: Story 2.1 - Register members through supported channels.
FR7: Story 2.1 - Manage complete member profiles.
FR8: Story 2.2 - Provide controlled member self-service updates.
FR9: Story 2.6 - Issue membership IDs and digital cards.
FR10: Story 2.5 - Detect and merge duplicate members.
FR11: Story 2.3 - Manage families and households.
FR12: Story 2.4 - Track membership lifecycle states.
FR13: Story 3.1 - Capture visitors and follow-up history.
FR14: Story 3.2 - Run visitor and member onboarding sequences.
FR15: Story 4.1 - Configure and manage church services.
FR16: Story 4.2 - Manage events and event operations.
FR17: Story 4.3 - Register and admit event attendees.
FR18: Story 4.4 - Capture attendance through supported methods.
FR19: Story 4.4 - Record attendance statuses.
FR20: Story 3.3 - Detect attendance exceptions and initiate follow-up.
FR21: Story 5.1 - Create and configure service teams.
FR22: Story 5.2 - Assign and organize team members.
FR23: Story 5.4 - Build rosters and resolve scheduling conflicts.
FR24: Story 5.5 - Track team and rehearsal attendance.
FR25: Story 5.6 - Submit and approve team reports.
FR26: Story 5.7 - Build team-specific reporting forms.
FR27: Story 5.8 - Provide team dashboards.
FR28: Story 5.3 - Maintain volunteer profiles and service history.
FR29: Story 5.9 - Recommend volunteers by skill and availability.
FR30: Stories 6.1-6.2 - Manage groups, meetings, participation, and follow-up.
FR31: Stories 7.1-7.2 - Create and assess welfare requests and cases.
FR32: Story 7.3 - Route configurable welfare approvals.
FR33: Stories 7.4-7.5 - Track assistance, follow-up, and welfare reporting.
FR34: Stories 8.1-8.2 - Create and process restricted pastoral care cases.
FR35: Story 8.2 - Execute care case workflows.
FR36: Stories 8.3-8.4 - Submit and process confidential prayer requests.
FR37: Story 3.4 - Manage follow-up tasks and outcomes.
FR38: Story 9.1 - Create and track operational tasks.
FR39: Stories 9.2-9.3 - Configure and execute reusable workflows.
FR40: Story 9.4 - Configure event-driven automation rules.
FR41: Story 10.1 - Deliver multi-channel communications.
FR42: Story 10.2 - Manage a personal notification inbox.
FR43: Story 10.3 - Create reusable message templates.
FR44: Story 10.4 - Automate birthday and anniversary messages.
FR45: Story 10.5 - Build and measure newsletters.
FR46: Story 10.6 - Operate moderated communication spaces.
FR47: Story 10.7 - Publish church content.
FR48: Stories 11.1-11.3 - Integrate, reconcile, receipt, and report restricted giving data.
FR49: Story 12.2 - Provide the member dashboard.
FR50: Story 12.3 - Provide the team-leader dashboard.
FR51: Story 12.4 - Provide the branch-administrator dashboard.
FR52: Story 12.5 - Provide the HQ leadership dashboard.
FR53: Story 13.1 - Configure role-specific dashboards.
FR54: Story 13.2 - Run standard church reports.
FR55: Story 13.3 - Build custom reports without code.
FR56: Story 13.4 - Export authorized report results.
FR57: Story 13.5 - Schedule and distribute reports.
FR58: Stories 14.1-14.2 - Manage protected, versioned church documents.
FR59: Story 14.3 - Search globally within effective permissions.
FR60: Story 2.7 - Provide a privacy-controlled member directory.
FR61: Stories 6.3-6.4 - Manage training, completion, and certification.
FR62: Story 4.5 - Collect and route service or event feedback.
FR63: Story 4.6 - Report and resolve operational incidents.
FR64: Stories 15.1-15.2 - Migrate and reconcile legacy data.
FR65: Stories 15.3-15.4 - Expose documented APIs and webhooks.
FR66: Story 15.5 - Integrate approved external services.
FR67: Story 1.8 - Record security and business audit events.
FR68: Story 1.7 - Configure platform behavior through administration.
FR69: Story 12.1 - Deliver approved Android and iOS hybrid workflows.
FR70: Story 15.6 - Monitor operational and security events.

## Epic List

### Epic 1: Govern the Church Securely
HQ administrators can establish the church hierarchy, use ShepardOne-owned replaceable Identity, manage scoped access, configure the platform, and audit sensitive actions.
**FRs covered:** FR1, FR2, FR3, FR4, FR5, FR67, FR68

### Epic 2: Manage Members and Households
Staff and members can register, maintain, deduplicate, organize, and privacy-control complete member and family records, including lifecycle status and digital identity.
**FRs covered:** FR6, FR7, FR8, FR9, FR10, FR11, FR12, FR60

### Epic 3: Welcome and Follow Up People
Teams can capture visitors and new members, run onboarding journeys, detect attendance concerns, assign follow-ups, and record outcomes.
**FRs covered:** FR13, FR14, FR20, FR37

### Epic 4: Run Services, Events, and Attendance
Coordinators can plan services and events, register and check in attendees, capture attendance through multiple methods, collect feedback, and resolve incidents.
**FRs covered:** FR15, FR16, FR17, FR18, FR19, FR62, FR63

### Epic 5: Coordinate Service Teams and Volunteers
Leaders can form teams, assign and schedule volunteers, capture attendance, submit configurable reports, view dashboards, and match volunteers to needs.
**FRs covered:** FR21, FR22, FR23, FR24, FR25, FR26, FR27, FR28, FR29

### Epic 6: Grow Groups and Discipleship
Leaders can operate groups and deliver membership, discipleship, leadership, and volunteer training with attendance, assessments, materials, and certificates.
**FRs covered:** FR30, FR61

### Epic 7: Deliver Welfare Assistance Accountably
Members and welfare officers can request, assess, approve, disburse, follow up, and report on assistance through configurable thresholds and restricted records.
**FRs covered:** FR31, FR32, FR33

### Epic 8: Provide Pastoral Care and Prayer Support
Authorized care and prayer teams can securely manage care cases, visits, counselling, prayer confidentiality, escalation, resolution, and closure.
**FRs covered:** FR34, FR35, FR36

### Epic 9: Automate Work, Tasks, and Approvals
Authorized users can coordinate tasks and configure reusable workflows, deadlines, approvals, escalation, reminders, and rules without code changes.
**FRs covered:** FR38, FR39, FR40

### Epic 10: Engage the Church Across Channels
Communications teams can send targeted multi-channel messages, manage templates and notification inboxes, automate milestones, publish content, and operate moderated community spaces.
**FRs covered:** FR41, FR42, FR43, FR44, FR45, FR46, FR47

### Epic 11: Record Giving and Contributions
Members and authorized finance users can integrate approved payments and access tightly controlled giving records, receipts, statements, campaigns, and reports.
**FRs covered:** FR48

### Epic 12: Serve Each Role on Web and Mobile
Members, team leaders, branch administrators, and HQ leaders can use role-specific dashboards and approved Android/iOS hybrid workflows through the Laravel API.
**FRs covered:** FR49, FR50, FR51, FR52, FR69

### Epic 13: Turn Church Data into Decisions
Authorized leaders can compose dashboards, run standard and custom reports, export results, and schedule permission-checked distribution.
**FRs covered:** FR53, FR54, FR55, FR56, FR57

### Epic 14: Find and Govern Church Records
Users can securely search across church operations and manage versioned, categorized, permission-controlled documents.
**FRs covered:** FR58, FR59

### Epic 15: Migrate, Integrate, and Operate Reliably
Administrators can migrate legacy data, connect approved external services through documented APIs, and monitor operational and security events.
**FRs covered:** FR64, FR65, FR66, FR70

## Epic 1: Govern the Church Securely

HQ administrators can establish the church hierarchy, use ShepardOne-owned replaceable Identity, manage scoped access, configure the platform, and audit sensitive actions.

### Story 1.1: Access the Platform through ShepardOne Identity

As a church platform user,
I want to sign in and sign out through ShepardOne Identity,
So that I can securely access the Vue application through a consistent, replaceable identity boundary.

**Acceptance Criteria:**

**Given** the canonical Identity contracts and selected Laravel adapters have been assessed for Laravel compatibility, web and mobile flows, session or device-credential behavior, recovery, lockout, MFA, audit hooks, OIDC, and extension points
**When** the adapters are integrated into the Laravel API and Vue application shell
**Then** clients and domain modules depend only on ShepardOne-owned Identity contracts
**And** adapter constraints and unsupported required capabilities are resolved before release without leaking package types across the boundary.

**Given** an active user with valid credentials
**When** the user signs in
**Then** the Identity adapter authenticates the user and the application returns only the minimum identity and authorization context required by the client
**And** the user reaches an accessible, responsive, role-appropriate application shell.

**Given** invalid credentials, a locked account, an inactive account, or an expired or revoked session
**When** access is attempted
**Then** access is denied with a non-disclosing error response
**And** the event is rate-limited and logged without recording credentials or tokens.

**Given** an authenticated user
**When** the user signs out
**Then** the current web session or mobile device credential is invalidated through the Identity contract
**And** protected API requests made with the invalidated credential are rejected.

### Story 1.2: Require MFA for Privileged Access

As an HQ security administrator,
I want privileged accounts to use multi-factor authentication,
So that administrative access remains protected if a password is compromised.

**Acceptance Criteria:**

**Given** a role marked as privileged and an approved TOTP authenticator adapter
**When** a user receives that role or next signs in
**Then** MFA enrollment is required before privileged functions can be used
**And** recovery methods follow the approved security policy.

**Given** a privileged user with MFA enrolled
**When** the user supplies a valid primary credential but no valid second factor
**Then** privileged access is denied
**And** the attempt is recorded as a security audit event.

**Given** a non-privileged user
**When** the user signs in
**Then** MFA behavior follows the configured policy for that role
**And** changing the policy does not require source-code modification.

### Story 1.3: Configure the Church Organization


As an HQ administrator,
I want to create and maintain the church's organizational hierarchy,
So that every record and responsibility can be associated with the correct church unit.

**Acceptance Criteria:**

**Given** an authorized HQ administrator
**When** the administrator creates headquarters, branches, campuses, locations, ministries, departments, teams, or groups with required profile fields
**Then** each unit is stored with a unique identifier and its valid parent relationship
**And** invalid hierarchy relationships or duplicate identifiers are rejected with clear validation.

**Given** an existing organizational unit with no blocking dependency
**When** an authorized administrator updates, deactivates, or archives it
**Then** the change is reflected without deleting historical relationships
**And** affected administrators receive clear warnings before consequential changes.

**Given** a branch administrator
**When** the administrator views organization settings
**Then** only the assigned branch and locally configurable descendants are manageable
**And** centrally governed fields remain read-only.

### Story 1.4: Isolate Branch Data and Consolidate HQ Views

As an HQ administrator,
I want branch-owned data isolated while authorized HQ views remain consolidated,
So that branches operate independently under central governance.

**Acceptance Criteria:**

**Given** a user assigned only to Branch A
**When** the user lists, searches, opens, exports, or calls an API for branch-owned records
**Then** only records within the user's effective Branch A scope are returned
**And** changing client parameters cannot expose records from another branch.

**Given** an authorized HQ user with church-wide scope
**When** the user requests a consolidated view
**Then** the response includes permitted data across branches with branch attribution
**And** restricted record classes remain filtered by the user's effective permissions.

**Given** a queued job, scheduled task, report, search index operation, or webhook handler
**When** it processes tenant-owned data
**Then** the same branch and permission boundaries used by interactive requests are enforced
**And** missing scope context causes a secure failure rather than unscoped processing.

### Story 1.5: Control Cross-Branch Identity Movement

As an authorized membership administrator,
I want one church-wide identity to move between branches through an approved process,
So that member history is preserved without creating duplicate people.

**Acceptance Criteria:**

**Given** an existing centrally identified person associated with one branch
**When** an authorized administrator initiates a transfer with destination, effective date, reason, and required approval
**Then** the system preserves the person's central identifier and historical branch relationship
**And** creates a pending movement record rather than a duplicate identity.

**Given** a pending movement
**When** an authorized destination or HQ approver approves it
**Then** the effective branch association changes on the approved date
**And** branch-scoped access before and after the date follows the approved movement and retention policy.

**Given** an unauthorized, invalid, duplicate, or rejected movement request
**When** processing is attempted
**Then** the active branch association remains unchanged
**And** the decision and reason are audited.

### Story 1.6: Manage Scoped Roles and Permissions

As an HQ security administrator,
I want configurable roles and scoped permissions,
So that each user can perform only authorized actions in the appropriate church context.

**Acceptance Criteria:**

**Given** an authorized security administrator
**When** the administrator creates or updates a role
**Then** permissions can be assigned by organization, branch, ministry, department, team, group, module, function, record type, and supported action
**And** changes are validated to prevent an administrator from granting scope they do not possess.

**Given** a user with multiple role assignments
**When** the user requests an action
**Then** Laravel policies, gates, middleware, and query scopes calculate and enforce the effective permission
**And** the same result applies through web, hybrid mobile, API, export, search, and background processing paths.

**Given** a permission is removed or a role assignment expires
**When** the affected user next requests the capability
**Then** access is denied without requiring a new deployment
**And** cached authorization context is invalidated within the agreed security window.

**Given** a proposed role change would remove the last viable super-administrator path
**When** the change is submitted
**Then** the system blocks it or requires an approved break-glass procedure
**And** records the attempted change.

### Story 1.7: Configure Governed Platform Settings

As an authorized church administrator,
I want to maintain supported operational settings through the application,
So that routine church changes do not require developer intervention.

**Acceptance Criteria:**

**Given** an administrator with permission for a configuration domain
**When** the administrator manages member statuses, categories, attendance types, approval levels, templates, notification settings, form fields, roles, or other supported settings
**Then** valid changes become available within the administrator's scope without source-code modification
**And** centrally locked settings cannot be overridden locally.

**Given** a configuration value is already referenced by operational records
**When** an administrator tries to delete it
**Then** destructive deletion is blocked and archival or replacement is offered where supported
**And** historical records continue to render accurately.

**Given** invalid, conflicting, or incomplete configuration
**When** the administrator attempts to publish it
**Then** publication is rejected with field-specific guidance
**And** the last valid configuration remains active.

### Story 1.8: Review Security and Business Audit Events

As an authorized auditor,
I want to search and inspect protected audit events,
So that the church can investigate access and hold users accountable for consequential actions.

**Acceptance Criteria:**

**Given** a configured auditable event such as login, logout, data access, creation, modification, deletion, approval, rejection, export, report generation, role change, or permission change
**When** the event occurs
**Then** an append-only audit record captures actor, timestamp, action, scope, affected record, request context, and permitted before-and-after values
**And** secrets and prohibited sensitive values are redacted.

**Given** an authorized auditor
**When** the auditor filters audit records by date, actor, branch, action, module, or affected record
**Then** matching records within the auditor's scope are returned
**And** viewing or exporting audit data is itself audited.

**Given** an unauthorized user or a user outside the audit record's scope
**When** audit access is attempted
**Then** no audit details are disclosed
**And** the denied attempt is recorded according to security policy.

**Given** an audit record within its retention period
**When** a user attempts to modify or delete it through supported application paths
**Then** the operation is denied
**And** retention or archival follows the approved privacy and compliance policy.

## Epic 2: Manage Members and Households

Staff and members can register, maintain, deduplicate, organize, and privacy-control complete member and family records, including lifecycle status and digital identity.

### Story 2.1: Register and Maintain Member Profiles

As a membership officer,
I want to register and maintain complete member profiles,
So that the church has one reliable record for each person.

**Acceptance Criteria:**

**Given** an authorized user selects web, mobile, reception, branch administration, QR, kiosk, or approved import registration
**When** required identity, contact, branch, consent, and membership fields pass validation
**Then** one member profile with a church-wide membership ID is created in the correct scope
**And** optional spiritual, skill, ministry, and communication preferences can be recorded according to permission.

**Given** invalid, incomplete, or potentially duplicated registration data
**When** registration is submitted
**Then** creation is blocked or routed to duplicate review as appropriate
**And** entered data is preserved with field-specific, accessible guidance.

**Given** an authorized user opens an existing member
**When** profile fields are changed or the record is archived
**Then** only permitted fields are updated, history remains linked to the member, and the change is audited
**And** restricted attendance, giving, welfare, prayer, and care data is omitted unless separately authorized.

### Story 2.2: Update My Profile Safely

As a member,
I want to update approved profile details,
So that the church can contact and support me using current information.

**Acceptance Criteria:**

**Given** an authenticated member
**When** the member updates an allowed field such as phone, email, address, photograph, occupation, or emergency contact
**Then** the value is validated and either applied immediately or submitted for approval according to field policy
**And** the member sees the resulting status.

**Given** a sensitive or staff-controlled field
**When** a member attempts to change it directly or by altering an API request
**Then** the unauthorized change is rejected
**And** the existing value remains unchanged.

**Given** a pending profile change requiring review
**When** an authorized officer approves or rejects it
**Then** the member is notified and an approved value becomes current
**And** the request, decision, and before-and-after values are audited.

### Story 2.3: Organize Members into Households

As a membership officer,
I want to group related members into households,
So that family relationships and shared church experiences are managed without duplicate data.

**Acceptance Criteria:**

**Given** existing member profiles
**When** an authorized officer creates a household and assigns a head, spouse, children, dependants, and relationship types
**Then** each person remains a distinct member linked to one household relationship model
**And** circular, contradictory, or duplicate active relationships are rejected.

**Given** an authorized user views a household
**When** the household is loaded
**Then** permitted contact details, members, milestones, attendance summaries, events, teams, and welfare references are shown
**And** restricted person-level records remain protected.

**Given** a marriage, separation, dependency, transfer, or other household change
**When** an authorized officer records the effective change
**Then** current relationships are updated without erasing history
**And** shared contact information does not overwrite person-specific data without confirmation.

### Story 2.4: Track the Member Lifecycle

As a membership officer,
I want to move members through configurable lifecycle stages and statuses,
So that the church can support each person's current journey accurately.

**Acceptance Criteria:**

**Given** configured stages and valid transition rules
**When** an authorized user advances a visitor, convert, or member to a permitted next stage
**Then** the new stage, effective date, reason, actor, and relevant milestone are recorded
**And** dependent follow-up can use the new stage as an event without requiring manual data duplication.

**Given** a transition requiring evidence or approval
**When** required information is missing or approval has not been granted
**Then** the transition is blocked with clear guidance
**And** the current lifecycle state remains active.

**Given** a member becomes inactive, transferred, relocated, unavailable, suspended, deceased, or archived
**When** the status is recorded
**Then** effective permissions and communications follow configured policy
**And** historical attendance, service, family, and care relationships are retained.

### Story 2.5: Review and Merge Duplicate Members

As a membership administrator,
I want to identify and safely merge duplicate member records,
So that church-wide history remains accurate and attributable to one person.

**Acceptance Criteria:**

**Given** registration, import, or profile updates
**When** configurable combinations of name, phone, email, birth date, family, or member ID indicate a possible match
**Then** the system assigns a reviewable confidence signal and flags the records
**And** no automatic destructive merge occurs.

**Given** an authorized reviewer compares potential duplicates
**When** the reviewer chooses the surviving values and confirms the merge
**Then** related household, branch, attendance, team, and other permitted history is re-linked transactionally to the survivor
**And** the retired identifier remains traceable and cannot be reused.

**Given** conflicting restricted records or a merge failure
**When** confirmation is attempted
**Then** the merge is blocked or rolled back completely
**And** the reviewer receives a non-sensitive explanation and an audited outcome.

### Story 2.6: Use a Digital Membership Card

As a member,
I want a verifiable digital membership card,
So that I can identify myself and use approved QR-enabled church services.

**Acceptance Criteria:**

**Given** an active member with the required card fields
**When** the member opens the digital card
**Then** it displays the current name, photograph, membership ID, branch, status, and a verifiable QR code
**And** it does not expose sensitive profile data in the QR payload.

**Given** an authorized scanner with a valid purpose
**When** the card QR code is scanned
**Then** the backend validates the signed or opaque reference and returns only purpose-appropriate member verification data
**And** unauthorized, expired, altered, or replay-protected codes fail securely.

**Given** the member's branch, status, photograph, or card eligibility changes
**When** the card is next viewed or validated
**Then** current approved data and eligibility are used
**And** cached mobile content cannot authorize a prohibited action.

### Story 2.7: Control My Church Directory Visibility

As a member,
I want to control which eligible details appear in the church directory,
So that I can participate in church community while protecting my privacy.

**Acceptance Criteria:**

**Given** an authenticated member
**When** the member reviews directory privacy settings
**Then** each eligible field such as photograph, phone, email, branch, department, team, or group shows its current visibility choice
**And** mandatory private fields cannot be published.

**Given** a member changes a visibility preference or withdraws directory consent
**When** the change is saved
**Then** future directory results reflect the choice within the agreed propagation period
**And** the consent change is timestamped and auditable.

**Given** a directory user searches or opens a member listing
**When** results are returned
**Then** only members and fields visible to that user under consent, relationship, role, and branch rules are shown
**And** exports, APIs, and search indexes enforce the same privacy decision.

## Epic 3: Welcome and Follow Up People

Teams can capture visitors and new members, run onboarding journeys, detect attendance concerns, assign follow-ups, and record outcomes.

### Story 3.1: Capture Visitors and Their Decisions

As a follow-up officer,
I want to record first-time and returning visitors,
So that the church can welcome them personally and respond to their expressed needs.

**Acceptance Criteria:**

**Given** a visitor at a service or event
**When** an authorized user records contact details, inviter, branch, attendance, prayer needs, decisions, salvation response, and membership interest with consent
**Then** a visitor record is created or linked to an existing person
**And** possible duplicates are presented for review before another identity is created.

**Given** a returning visitor
**When** a new visit is recorded
**Then** the visit and any new decision are appended to the existing history
**And** the original source and prior follow-up remain intact.

**Given** a confidential prayer need or restricted response
**When** the visitor record is viewed
**Then** only users with the required scope can access that information
**And** ordinary visitor lists and exports omit it.

### Story 3.2: Run Configurable Welcome and Onboarding Journeys

As a membership coordinator,
I want visitors, converts, and new members enrolled in configurable onboarding journeys,
So that each person receives timely communication and next steps.

**Acceptance Criteria:**

**Given** an eligible lifecycle event and a published journey
**When** the event occurs
**Then** the person is enrolled once and scheduled steps can create messages, tasks, reminders, milestones, or escalation using their branch, consent, and channel preferences
**And** the journey version used is recorded.

**Given** a journey containing Day 0, Day 1, Day 3, Day 7, Day 14, or Day 30 actions
**When** each due time arrives
**Then** the queued action executes idempotently or records a retryable failure
**And** staff can see completed, pending, skipped, and failed steps.

**Given** consent withdrawal, changed lifecycle status, duplicate enrollment, or a configured stop condition
**When** a future step becomes due
**Then** prohibited actions are skipped and the reason is logged
**And** unrelated valid journey steps follow current policy.

### Story 3.3: Detect Attendance Exceptions

As a pastoral leader,
I want configured attendance concerns identified automatically,
So that people at risk of disengagement receive timely care.

**Acceptance Criteria:**

**Given** published rules for consecutive absence, declining attendance, no return after a first visit, or repeated team absence
**When** qualifying attendance is recorded or corrected
**Then** the rules evaluate the relevant person, branch, service type, and time window
**And** one open exception is created per rule and qualifying period.

**Given** excused absence, online attendance, branch transfer, service cancellation, or insufficient history
**When** rules evaluate the record
**Then** configured exclusions are applied
**And** no unsupported conclusion is generated.

**Given** source attendance is corrected after an exception is created
**When** the rule is re-evaluated
**Then** the exception is resolved, retained, or flagged for review according to policy
**And** its audit history explains the change.

### Story 3.4: Complete Assigned Follow-Up

As a follow-up officer,
I want assigned follow-up work with due dates and outcome tracking,
So that no visitor, member, beneficiary, requester, or participant is forgotten.

**Acceptance Criteria:**

**Given** an authorized source event or manual referral
**When** a follow-up is created
**Then** it includes person, reason, assignee, due date, allowed contact method, priority, and source reference
**And** the assignee is notified without exposing restricted details.

**Given** an assigned officer
**When** the officer records a contact attempt, outcome, notes, and next action
**Then** the history is timestamped and visible only within effective scope
**And** a next task or closure can be selected according to status rules.

**Given** an overdue, unsuccessful, declined, or high-risk follow-up
**When** its escalation rule is met
**Then** it is reassigned or escalated to the configured role and branch
**And** duplicate escalations are not generated.

## Epic 4: Run Services, Events, and Attendance

Coordinators can plan services and events, register and check in attendees, capture attendance through multiple methods, collect feedback, and resolve incidents.

### Story 4.1: Schedule Church Services

As a service coordinator,
I want to schedule and maintain church services,
So that participants and serving teams have one reliable operating plan.

**Acceptance Criteria:**

**Given** an authorized coordinator
**When** a service is created with type, date, time, branch, venue, ministers, teams, capacity, registration, targets, and livestream details
**Then** the service is available in the correct branch schedule
**And** conflicting or invalid time, venue, capacity, and leadership data is flagged.

**Given** a published service
**When** an authorized coordinator changes or cancels it
**Then** the current schedule and change history are retained
**And** affected registrations, rosters, and notification processes receive the change event.

**Given** a user without the service's branch or function scope
**When** creation or modification is attempted
**Then** access is denied
**And** no partial change is persisted.

### Story 4.2: Plan and Operate Events

As an event coordinator,
I want to create and manage church events,
So that registrations, volunteers, materials, tasks, communications, and results stay coordinated.

**Acceptance Criteria:**

**Given** an authorized event coordinator
**When** an event is configured with schedule, venue, branch, capacity, speakers, registration, ticketing policy, volunteers, materials, budget visibility, and reminders
**Then** a draft event is saved and can be reviewed before publication
**And** restricted budget fields are visible only to authorized roles.

**Given** a valid draft event
**When** it is published, updated, postponed, or cancelled
**Then** its public status and registration availability follow the change
**And** affected participants and operators can be notified through configured channels.

**Given** a completed event
**When** an authorized coordinator closes it
**Then** registrations, attendance, volunteer participation, feedback, incidents, and permitted budget results are available for post-event reporting
**And** operational records become protected from casual modification.

### Story 4.3: Register and Admit Event Participants

As a member or guest,
I want to register for an eligible event and receive a QR credential,
So that I can receive confirmation and enter efficiently.

**Acceptance Criteria:**

**Given** an open event with available capacity
**When** a person registers through web, hybrid mobile, staff assistance, or QR entry and supplies required consent and fields
**Then** a unique registration is created with confirmation and an opaque or signed QR credential
**And** duplicate registration rules and any payment requirement are enforced.

**Given** an event at capacity, closed registration, unmet eligibility, or invalid input
**When** registration is attempted
**Then** it is rejected or waitlisted according to event policy
**And** the person receives a clear next step without sensitive disclosure.

**Given** an authorized event scanner
**When** a valid unused credential is scanned
**Then** registration is validated, attendance is marked once, and an event pass is returned where configured
**And** invalid, cancelled, wrong-event, or duplicate scans show a safe operator response.

### Story 4.4: Capture Attendance Through Approved Methods

As an attendance officer,
I want to capture attendance using the method appropriate to the gathering,
So that participation is recorded quickly and accurately.

**Acceptance Criteria:**

**Given** a configured service, event, department, team, group, or training session
**When** attendance is captured manually, by QR, mobile check-in, member ID, barcode, kiosk, or authorized entry
**Then** one attendance record per person and session is created with method, time, recorder or device, and status
**And** supported statuses include present, absent, excused, late, online, first-timer, and visitor.

**Given** duplicate, stale, wrong-branch, wrong-session, unauthorized, or invalid check-in data
**When** capture is attempted
**Then** the record is rejected or presented for authorized resolution
**And** throughput remains suitable for the agreed peak attendance load.

**Given** a recoverable mobile connectivity interruption
**When** an approved offline-tolerant check-in is captured
**Then** it is stored securely with visible pending status and later synchronized idempotently
**And** conflicts are surfaced for authorized resolution without losing the original entry.

**Given** an authorized correction after attendance closes
**When** status or identity is amended with a reason
**Then** downstream metrics are recalculated
**And** the original and corrected values remain auditable.

### Story 4.5: Route Service and Event Feedback

As a participant,
I want to submit categorized feedback after a service or event,
So that the responsible church team can improve the experience.

**Acceptance Criteria:**

**Given** an eligible completed gathering
**When** a participant submits feedback for facilities, sound, media, ushering, children, parking, security, or general experience
**Then** the feedback is linked to the gathering and routed to the configured responsible team
**And** identity or anonymous handling follows the published policy.

**Given** abusive content, prohibited attachments, or an invalid gathering reference
**When** feedback is submitted
**Then** it is rejected or held for moderation according to policy
**And** the submitter receives a safe status response.

**Given** an assigned team member
**When** feedback is acknowledged, acted on, reassigned, or closed
**Then** status and response history are recorded
**And** the participant is notified only where consent and policy allow.

### Story 4.6: Resolve Operational Incidents

As a service-team leader,
I want to report and manage incidents,
So that emergencies, failures, and complaints receive accountable resolution.

**Acceptance Criteria:**

**Given** an authorized user reports a medical, child-safety, security, equipment, complaint, or technical incident
**When** required time, location, classification, description, priority, and permitted evidence are submitted
**Then** a unique incident is created and assigned according to severity and branch rules
**And** sensitive details are restricted to the response team.

**Given** an open incident
**When** responders record investigation, actions, reassignment, escalation, or resolution
**Then** the chronological history and current owner are preserved
**And** overdue or critical conditions trigger configured escalation without duplicate alerts.

**Given** a resolved incident requiring management review
**When** an authorized reviewer approves closure or returns it
**Then** closure captures outcome and follow-up actions or the incident returns to an accountable owner
**And** all consequential changes are audited.

## Epic 5: Coordinate Service Teams and Volunteers

Leaders can form teams, assign and schedule volunteers, capture attendance, submit configurable reports, view dashboards, and match volunteers to needs.

### Story 5.1: Create and Configure a Service Team

As a volunteer coordinator,
I want to create a service team with its operating rules,
So that leaders and members understand how the team serves.

**Acceptance Criteria:**

**Given** an authorized coordinator
**When** team name, branch, department, category, description, leaders, required skills, minimum staffing, schedules, objectives, attendance rules, reporting template, and approval hierarchy are valid
**Then** the team is created in the correct scope
**And** duplicate, cross-scope, or contradictory configuration is rejected.

**Given** an active team with operational history
**When** it is reconfigured or archived
**Then** future operations use the effective configuration while historical records retain their original context
**And** impacted leaders are notified of material changes.

### Story 5.2: Assign Members to Teams and Duties

As a team administrator,
I want to assign members to teams, roles, sub-teams, shifts, and responsibilities,
So that service duties have accountable people.

**Acceptance Criteria:**

**Given** eligible members and an authorized administrator
**When** individual or bulk assignments, transfers, removals, roles, sub-teams, shifts, or responsibilities are submitted
**Then** valid assignments take effect on their configured dates
**And** church policy for multiple teams, branch scope, eligibility, and approval is enforced.

**Given** an assignment conflicts with membership status, training, availability, another duty, or team capacity
**When** it is submitted
**Then** the conflict is blocked or explicitly overridden only by an authorized user with a reason
**And** the decision is audited.

### Story 5.3: Maintain Volunteer Profiles

As a volunteer coordinator,
I want a centralized record of each volunteer's capabilities and service,
So that assignments and development decisions use reliable information.

**Acceptance Criteria:**

**Given** an eligible member
**When** skills, expertise, availability, preferences, experience, certifications, training, service history, teams, and volunteer hours are recorded
**Then** a permission-controlled volunteer profile is linked to the member
**And** expiring certifications and unavailable periods can be identified.

**Given** a volunteer or authorized coordinator updates self-declared or verified attributes
**When** the change is saved
**Then** verification rules and effective dates are applied
**And** restricted notes are not exposed to the volunteer or unrelated leaders.

### Story 5.4: Publish Team Rosters

As a team leader,
I want to create and publish weekly, monthly, event, and shift rosters,
So that every required duty has a confirmed volunteer.

**Acceptance Criteria:**

**Given** a service or event and eligible team members
**When** a leader assigns duties and shifts
**Then** staffing requirements, availability, conflicts, and duplicate assignments are shown before publication
**And** only a valid or explicitly approved roster can be published.

**Given** a published assignment
**When** a member accepts, rejects with reason, or requests replacement
**Then** the leader is notified and roster status updates
**And** an approved substitute can replace the member without erasing assignment history.

### Story 5.5: Track Team Attendance and Reliability

As a team leader,
I want independent attendance for duties and rehearsals,
So that I can identify reliable participation and needed follow-up.

**Acceptance Criteria:**

**Given** a rostered duty, meeting, or rehearsal
**When** authorized attendance is captured
**Then** present, absent, excused, and late status is linked to the team occurrence and member
**And** general gathering attendance is not silently substituted for team attendance.

**Given** sufficient team attendance history
**When** a leader opens attendance analysis
**Then** totals, percentage, trend, reliability indicators, and members requiring follow-up are calculated within scope
**And** corrections recalculate derived values with an audit trail.

### Story 5.6: Submit and Approve Team Reports

As a team leader,
I want to submit an electronic report for a service or reporting period,
So that supervisors can review team activity and issues consistently.

**Acceptance Criteria:**

**Given** an applicable reporting template
**When** the leader completes required fields, attachments, incidents, concerns, results, and recommendations
**Then** a versioned draft can be saved and a valid report submitted once
**And** submitted content becomes read-only to the author unless returned.

**Given** a submitted report
**When** the configured reviewer approves or returns it with comments
**Then** status, decision, actor, and timestamp are recorded and relevant parties notified
**And** only approved results feed consolidated metrics.

### Story 5.7: Build Team-Specific Report Forms

As an authorized administrator,
I want to configure report forms without code,
So that each service team can capture its unique KPIs.

**Acceptance Criteria:**

**Given** an authorized form administrator
**When** fields of supported text, number, date, dropdown, attachment, image, percentage, rating, or checkbox types are arranged and validated
**Then** a draft form version can be previewed and published for selected teams
**And** required rules, options, help text, and permitted file constraints are enforced.

**Given** reports already use a published form
**When** the form changes
**Then** a new version applies to future reports while historical submissions remain renderable
**And** incompatible changes require explicit migration or a new field.

### Story 5.8: Use a Team Operations Dashboard

As a team leader,
I want one dashboard for my team's current work,
So that I can act on staffing, attendance, reports, tasks, training, events, and issues.

**Acceptance Criteria:**

**Given** a leader with one or more assigned teams
**When** the team dashboard opens
**Then** permission-scoped membership, attendance, roster, assignments, pending reports, tasks, training, events, notifications, indicators, and issues are shown
**And** stale, loading, empty, and failed widgets communicate their state accessibly.

**Given** a leader selects a metric or action
**When** drill-down opens
**Then** the underlying filtered records and available next actions match the displayed metric
**And** switching teams cannot leak data from another scope.

### Story 5.9: Recommend Suitable Volunteers

As a volunteer coordinator,
I want ranked volunteer suggestions for an open duty,
So that I can quickly find qualified and available people while retaining human judgment.

**Acceptance Criteria:**

**Given** an open duty with required skills, time, branch, training, and eligibility rules
**When** recommendations are requested
**Then** eligible volunteers are ranked using documented skills and availability criteria with understandable reasons
**And** restricted profile information is not exposed in the recommendation.

**Given** no suitable candidate, stale profile data, a scheduling conflict, or an unavailable volunteer
**When** recommendations are generated or selected
**Then** the limitation is shown and unsafe assignment is blocked or requires authorized override
**And** the coordinator makes and confirms the final assignment.

## Epic 6: Grow Groups and Discipleship

Leaders can operate groups and deliver membership, discipleship, leadership, and volunteer training with attendance, assessments, materials, and certificates.

### Story 6.1: Create and Organize Church Groups

As a group coordinator,
I want to create cells, fellowships, classes, and interest groups,
So that members can gather under accountable leadership.

**Acceptance Criteria:**

**Given** an authorized coordinator
**When** a group is created with type, branch, leaders, meeting pattern, capacity, eligibility, communication, and reporting settings
**Then** it is available in the correct organizational scope
**And** invalid leaders, schedules, or cross-branch relationships are rejected.

**Given** eligible members
**When** they are assigned, transferred, removed, or approved from a join request
**Then** effective membership and role history are retained
**And** capacity, age, consent, safeguarding, and branch rules are enforced where configured.

### Story 6.2: Run Group Meetings and Follow-Up

As a group leader,
I want to schedule meetings, record participation, and submit group activity,
So that the group can care for members and remain accountable.

**Acceptance Criteria:**

**Given** an active group
**When** a leader schedules a meeting or activity and records attendance, notes, permitted prayer needs, actions, and report fields
**Then** the activity is linked to the group and visible within scope
**And** sensitive notes use their required confidentiality classification.

**Given** an absence pattern, visitor, member need, or overdue action
**When** configured follow-up criteria are met
**Then** a scoped follow-up can be assigned with due date and reason
**And** group dashboard totals reflect corrected attendance and completed actions.

### Story 6.3: Publish Training and Discipleship Offerings

As a training coordinator,
I want to configure courses, classes, schedules, materials, and enrolment rules,
So that members can join appropriate development pathways.

**Acceptance Criteria:**

**Given** an authorized coordinator
**When** a membership, new-believer, leadership, volunteer, or ministry course is configured with sessions, capacity, prerequisites, facilitators, assessments, materials, and completion rules
**Then** a versioned offering can be published to eligible members
**And** protected materials and facilitator data respect permissions.

**Given** an eligible member or authorized registrar
**When** enrolment is requested
**Then** prerequisites and capacity are evaluated and enrolment, waitlist, or rejection status is recorded
**And** the member receives the schedule and permitted materials.

### Story 6.4: Track Training Completion and Certification

As a training facilitator,
I want to track attendance, assessments, completion, and certificates,
So that the church can verify readiness for membership, leadership, or service.

**Acceptance Criteria:**

**Given** enrolled learners and scheduled sessions
**When** attendance and assessment results are recorded
**Then** each learner's progress is calculated using the offering's published completion rules
**And** corrections retain actor, reason, and prior value.

**Given** a learner satisfies all completion requirements
**When** completion is confirmed by an authorized facilitator
**Then** a verifiable certificate is issued with course version, completion date, and unique reference
**And** duplicate issuance or unauthorized result changes are prevented.

**Given** a learner does not satisfy requirements or a credential is revoked
**When** status is reviewed
**Then** the unmet criteria or revocation status is visible to authorized users
**And** eligibility checks no longer treat the credential as valid.

## Epic 7: Deliver Welfare Assistance Accountably

Members and welfare officers can request, assess, approve, disburse, follow up, and report on assistance through configurable thresholds and restricted records.

### Story 7.1: Submit a Welfare Request

As a member or authorized welfare officer,
I want to submit a welfare request with supporting information,
So that a need can enter a confidential and accountable review process.

**Acceptance Criteria:**

**Given** an eligible requester or authorized representative
**When** beneficiary, request type, description, priority, value where applicable, consent, and valid supporting documents are submitted
**Then** a unique case is created in Draft or Submitted status within the correct branch
**And** access is limited to the requester and authorized welfare roles.

**Given** incomplete, invalid, oversized, unsafe, or duplicate supporting data
**When** submission is attempted
**Then** invalid items are rejected with accessible guidance and safe file handling
**And** a recoverable draft preserves valid entered data.

### Story 7.2: Assess and Recommend Welfare Assistance

As a welfare officer,
I want to assess an assigned request and record a recommendation,
So that approvers receive sufficient evidence for a fair decision.

**Acceptance Criteria:**

**Given** a submitted case assigned within the officer's scope
**When** the officer records assessment, verified documents, priority, recommendation, proposed assistance, value, and follow-up needs
**Then** a time-stamped assessment version is stored and the case advances to review when complete
**And** the officer cannot approve a level prohibited by segregation-of-duties policy.

**Given** missing evidence, a conflict of interest, duplicate assistance concern, or need for clarification
**When** the officer records the condition
**Then** the case returns for information, is reassigned, or is escalated according to policy
**And** the beneficiary receives only an appropriate status message.

### Story 7.3: Route Welfare Approval by Configured Threshold

As an authorized welfare approver,
I want requests routed through the correct approval levels,
So that assistance follows church policy and monetary authority.

**Acceptance Criteria:**

**Given** a completed recommendation and effective approval configuration
**When** the proposed value and case attributes are evaluated
**Then** the required branch, HQ, executive, and finance approval sequence is created from the effective threshold rules
**And** the configuration version is retained with the case.

**Given** the current authorized approver
**When** approval, rejection, return, or escalation is submitted with required reason
**Then** the immutable decision history is updated and the next valid state or approver is selected
**And** users cannot approve their own request or bypass required levels.

**Given** a threshold changes while a case is active
**When** routing is reevaluated
**Then** the case follows the published policy for in-flight requests
**And** no completed approval is silently discarded.

### Story 7.4: Record Assistance Delivery and Confirmation

As an authorized welfare or finance officer,
I want to record approved assistance and beneficiary confirmation,
So that the church can account for what was actually delivered.

**Acceptance Criteria:**

**Given** a fully approved case
**When** an authorized officer records disbursement or in-kind assistance with amount or value, method, date, reference, and permitted evidence
**Then** the delivery is linked to the case and cannot exceed approval without a new approval path
**And** financial details remain restricted by role.

**Given** assistance has been delivered
**When** beneficiary confirmation is captured or formally waived with reason
**Then** confirmation status and evidence are retained
**And** the case advances to follow-up rather than closing automatically.

### Story 7.5: Follow Up and Report on Welfare Cases

As a welfare manager,
I want to track follow-up, close cases, and view authorized welfare results,
So that assistance outcomes and outstanding obligations remain visible.

**Acceptance Criteria:**

**Given** a delivered or pending welfare case
**When** an officer records follow-up date, outcome, further action, reassignment, or closure evidence
**Then** the status follows Draft, Submitted, Under Review, Approved, Rejected, Pending, Disbursed, Follow-up, and Closed transition rules
**And** overdue follow-up triggers configured reminders or escalation.

**Given** an authorized manager requests welfare reporting
**When** results are filtered by branch, category, status, beneficiary count, value, or period
**Then** permitted cases, assistance, expenditure, and outstanding work are calculated accurately
**And** reports and exports minimize beneficiary identity unless explicitly authorized.

## Epic 8: Provide Pastoral Care and Prayer Support

Authorized care and prayer teams can securely manage care cases, visits, counselling, prayer confidentiality, escalation, resolution, and closure.

### Story 8.1: Create a Restricted Care Case

As an authorized pastoral care officer,
I want to record a member care need confidentially,
So that the right caregiver can respond with appropriate context.

**Acceptance Criteria:**

**Given** a hospital visit, bereavement, counselling, marriage or family need, new baby, emergency, pastoral visit, or follow-up need
**When** an authorized user records beneficiary, category, description, priority, consent basis, confidentiality, and permitted evidence
**Then** a unique restricted case is created in the correct branch and assigned to an eligible care role
**And** sensitive fields are encrypted or otherwise protected according to data classification.

**Given** a user lacks the case's explicit role, branch, or sensitivity clearance
**When** the user lists, searches, opens, exports, or receives a notification about the case
**Then** the case or restricted details are omitted
**And** access attempts follow the sensitive-data audit policy.

### Story 8.2: Deliver and Close Pastoral Care

As an assigned care officer,
I want to record care actions, visits, follow-up, and resolution,
So that the member receives continuous care with accountable handoffs.

**Acceptance Criteria:**

**Given** an assigned open case
**When** the officer records a contact, visit, care action, outcome, restricted note, and next follow-up
**Then** a chronological entry is added and the next responsibility is clear
**And** prior entries cannot be silently overwritten.

**Given** urgency, safeguarding concern, missed deadline, unavailable officer, or unresolved need
**When** escalation criteria are met
**Then** the case is escalated to the configured qualified role without disclosing details to unrelated users
**And** the escalation and acknowledgement are audited.

**Given** resolution criteria are satisfied
**When** an authorized officer closes the case
**Then** outcome, closure reason, and any future care plan are recorded
**And** reopening requires permission and a reason.

### Story 8.3: Submit a Prayer Request with Confidentiality

As a member,
I want to submit a prayer request for a chosen audience,
So that I can receive prayer without losing control of sensitive information.

**Acceptance Criteria:**

**Given** an authenticated member or authorized assisted-submission user
**When** request, category, priority, and one of Private, Prayer Team Only, Pastor Only, Group, or Public/Testimony scope are submitted
**Then** the request is stored with the selected confidentiality and consent
**And** only eligible audiences can discover its content.

**Given** a member narrows confidentiality or withdraws a public/testimony request
**When** the change is saved
**Then** future views and indexes reflect the stricter choice within the agreed propagation period
**And** prior authorized processing remains auditable without continuing public exposure.

### Story 8.4: Process Prayer Requests Safely

As an authorized prayer-team leader,
I want to assign and track prayer requests,
So that requests receive attention while their confidentiality is respected.

**Acceptance Criteria:**

**Given** a request visible to the leader's team
**When** it is assigned, acknowledged, updated, escalated, marked answered, or closed
**Then** status, assignee, permitted notes, and timestamps are retained
**And** notifications reveal only the minimum information appropriate to the recipient.

**Given** a request is private, pastor-only, outside branch scope, or no longer consented for sharing
**When** assignment or group publication is attempted
**Then** the operation is denied
**And** no content is exposed through lists, search, analytics, logs, or message previews.

## Epic 9: Automate Work, Tasks, and Approvals

Authorized users can coordinate tasks and configure reusable workflows, deadlines, approvals, escalation, reminders, and rules without code changes.

### Story 9.1: Assign and Complete Operational Tasks

As a church staff member,
I want to create, assign, and track tasks,
So that operational responsibilities have owners and deadlines.

**Acceptance Criteria:**

**Given** an authorized user
**When** a task is created with description, assignee, branch, department, priority, due date, permitted attachment, and optional source record
**Then** it is visible to the creator, assignee, and authorized supervisors
**And** assignment outside the creator's scope is rejected.

**Given** an open task
**When** an authorized user changes status among Open, In Progress, Pending, Completed, Overdue, or Cancelled
**Then** transition rules, completion evidence, actor, and timestamp are recorded
**And** overdue status and reminders are calculated without duplicate notifications.

### Story 9.2: Design and Publish a Workflow

As an authorized process administrator,
I want to configure reusable workflows without code,
So that church approvals and handoffs follow consistent policy.

**Acceptance Criteria:**

**Given** an authorized administrator
**When** triggers, conditions, assignments, approvals, rejection, escalation, notifications, deadlines, reminders, and end states form a valid workflow
**Then** the draft can be visualized, validated, tested with sample data, and published as a version
**And** unreachable states, missing actors, loops without limits, or privilege escalation are blocked.

**Given** active workflow instances use a published version
**When** an administrator publishes a change
**Then** new instances use the new version and existing instances follow the declared migration policy
**And** prior versions and test evidence remain inspectable.

### Story 9.3: Execute Workflow Actions and Escalations

As a workflow participant,
I want assigned approval and action steps to advance reliably,
So that cases do not stall or bypass policy.

**Acceptance Criteria:**

**Given** a published workflow trigger
**When** a qualifying domain event occurs
**Then** one workflow instance starts idempotently with current scope, configuration version, due dates, and first assignment
**And** prohibited or incomplete context causes a visible secure failure.

**Given** the current authorized participant
**When** the participant approves, rejects, returns, completes, or reassigns a step
**Then** only a valid transition occurs and the next action is scheduled
**And** the decision, comment, actor, and timestamp are immutable history.

**Given** a deadline passes without valid action
**When** the scheduler evaluates the instance
**Then** the configured reminder or escalation occurs once per rule window
**And** retries remain idempotent and operationally observable.

### Story 9.4: Configure Event-Driven Automation Rules

As an authorized church administrator,
I want to configure rules for common domain events,
So that repetitive attendance, birthday, team, and welfare actions happen consistently.

**Acceptance Criteria:**

**Given** an authorized administrator
**When** a rule defines a supported event, conditions, action, scope, effective period, priority, and stop behavior
**Then** it can be simulated against sample data and published without source-code changes
**And** unsupported actions, circular chains, conflicting rules, or excessive fan-out are blocked or warned according to policy.

**Given** a published rule and qualifying event
**When** evaluation occurs
**Then** the permitted task, notification, workflow, or other supported action executes once with traceable rule and event references
**And** failures are retried or quarantined according to configuration.

**Given** a rule is disabled, superseded, outside its effective period, or lacks consent or authorization
**When** a matching event occurs
**Then** no prohibited action runs
**And** the skipped evaluation is available to authorized operators without sensitive payload leakage.

## Epic 10: Engage the Church Across Channels

Communications teams can send targeted multi-channel messages, manage templates and notification inboxes, automate milestones, publish content, and operate moderated community spaces.

### Story 10.1: Send Permission-Aware Communications

As a communications officer,
I want to send immediate, scheduled, recurring, event-based, or workflow-based messages,
So that the right people receive timely church communication through their permitted channels.

**Acceptance Criteria:**

**Given** an authorized sender, valid audience, approved content, purpose, and configured email, SMS, push, in-app, or external channel
**When** a communication is submitted or becomes due
**Then** recipients are resolved using current branch, role, group, consent, and preference rules and delivery is queued
**And** duplicate delivery is prevented for the same message and recipient.

**Given** missing consent, an unsubscribe, invalid destination, quiet-hours policy, provider failure, or prohibited sensitive content
**When** delivery is evaluated
**Then** the message is skipped, deferred, retried, or failed according to channel policy
**And** authorized operators can see status without exposing message content unnecessarily.

**Given** a bulk audience
**When** delivery runs
**Then** rate limits, provider quotas, batching, retries, and suppression lists are respected
**And** application responsiveness remains within agreed targets.

### Story 10.2: Manage My Notification Inbox

As a user,
I want one notification inbox with clear status controls,
So that I can find and act on important church updates.

**Acceptance Criteria:**

**Given** an authenticated user
**When** the notification center opens
**Then** only that user's permitted Service, Event, Team, Welfare, Care, Birthday, Prayer, Administrative, and System notifications are shown in newest-first order
**And** unread count, loading, empty, and error states are accessible.

**Given** a visible notification
**When** the user marks it read, unread, or archived or follows its approved deep link
**Then** status is synchronized across web and hybrid clients
**And** the destination independently rechecks authorization.

### Story 10.3: Create Reusable Message Templates

As a communications administrator,
I want versioned email and SMS templates with safe variables,
So that recurring messages remain consistent and personalized.

**Acceptance Criteria:**

**Given** an authorized administrator
**When** a template is created for a supported scenario with subject, body, channel, language, and approved variables
**Then** it can be previewed using non-sensitive sample data and published as a version
**And** unknown variables, unsafe markup, prohibited links, and channel length violations are rejected.

**Given** a published template referenced by scheduled work
**When** it is edited or retired
**Then** version and effective-date rules determine which content is sent
**And** prior delivery records retain the rendered version reference.

### Story 10.4: Automate Birthday and Anniversary Greetings

As a member-engagement officer,
I want personalized milestone greetings sent automatically,
So that members are recognized consistently on meaningful dates.

**Acceptance Criteria:**

**Given** a member has an eligible birthday, wedding, membership, baptism, ordination, or service anniversary and valid consent
**When** the configured detection window runs
**Then** the approved template is personalized and queued through permitted channels once per milestone period
**And** configured team alerts or birthday lists contain only approved details.

**Given** an invalid date, deceased or excluded status, withdrawn consent, missing destination, or prior successful delivery
**When** automation evaluates the member
**Then** no inappropriate or duplicate greeting is sent
**And** the skip or failure reason is logged for authorized review.

### Story 10.5: Build, Approve, and Measure Newsletters

As a communications officer,
I want to compose and schedule visual newsletters,
So that church news and events reach members in an engaging, measurable format.

**Acceptance Criteria:**

**Given** an authorized editor
**When** approved text, images, buttons, events, announcements, verses, birthdays, schedules, social links, and custom sections are arranged
**Then** a responsive draft can be previewed across supported screen sizes and sent as a test
**And** inaccessible content, unsafe markup, and missing required unsubscribe controls are flagged.

**Given** a valid draft
**When** the configured reviewer approves and schedules it
**Then** the locked approved version is delivered to the permission- and consent-filtered audience at the due time
**And** material edits require renewed approval.

**Given** delivery activity is received from configured providers
**When** an authorized user views analytics
**Then** sent, delivered, opened, clicked, bounced, and unsubscribed totals are shown with provider limitations disclosed
**And** tracking follows privacy and consent policy.

### Story 10.6: Operate Moderated Community Spaces

As a member of a church community,
I want to communicate in authorized church, branch, ministry, team, cell, department, or event spaces,
So that my community can coordinate and encourage one another safely.

**Acceptance Criteria:**

**Given** a user belongs to or may access a communication space
**When** the user sends permitted text, image, document, voice note, poll, or announcement content
**Then** the message appears to authorized participants with sender and timestamp
**And** file validation, size limits, consent, and retention rules are enforced.

**Given** a moderator
**When** content is pinned, restricted, removed, reported, or a participant is moderated
**Then** the visible state follows policy and the moderation action is audited
**And** removed sensitive content is not exposed through search or previews.

**Given** a large-scale messaging need exceeds the approved in-app architecture
**When** an authorized integration is configured
**Then** the external service is used through documented consent, identity mapping, and moderation boundaries
**And** the platform does not silently create an unsupported parallel messaging infrastructure.

### Story 10.7: Publish Church Content

As a content administrator,
I want to publish announcements, verses, news, sermons, articles, testimonies, media, downloads, and events,
So that members can access current approved church content.

**Acceptance Criteria:**

**Given** an authorized editor
**When** content with type, title, body, media, audience, branch, publish window, and visibility is saved
**Then** a draft can be previewed and routed through configured approval
**And** unsafe uploads, invalid links, and missing accessibility metadata are rejected.

**Given** approved content within its publish window
**When** an eligible user opens the content area
**Then** current content appropriate to role, branch, device, and audience is available
**And** expired, withdrawn, or unauthorized content is omitted from lists, search, feeds, and APIs.

## Epic 11: Record Giving and Contributions

Members and authorized finance users can integrate approved payments and access tightly controlled giving records, receipts, statements, campaigns, and reports.

### Story 11.1: Connect an Approved Payment Source

As a finance administrator,
I want to configure the church's approved payment platform or gateway,
So that confirmed contributions can enter the system without exposing payment secrets.

**Acceptance Criteria:**

**Given** an approved provider and authorized finance administrator
**When** credentials, webhook verification, supported categories, branch mapping, currency, and environment settings are configured through protected secrets management
**Then** the connection can be tested without storing raw credentials in source code or logs
**And** only authorized roles can change financial integration settings.

**Given** a signed provider event for a payment reference
**When** the webhook is validated and processed
**Then** the contribution is created or updated idempotently with provider, reference, status, amount, category, payer linkage where consented, and branch
**And** invalid signatures, replayed events, or conflicting amounts are rejected and monitored.

### Story 11.2: Reconcile Contributions and Issue Receipts

As an authorized finance officer,
I want to review and reconcile contribution records,
So that church giving history and receipts match confirmed funds.

**Acceptance Criteria:**

**Given** an imported, integrated, or authorized manual contribution
**When** it is matched to a member, category, campaign, branch, and payment reference
**Then** reconciliation status and actor are recorded without altering the provider's original evidence
**And** duplicate references or mismatched values require resolution.

**Given** a successfully confirmed contribution eligible for a receipt
**When** receipt generation is requested or automated
**Then** one verifiable receipt is produced with approved financial fields and delivered according to consent
**And** voids or corrections create traceable adjustments rather than deleting history.

### Story 11.3: View Giving History, Statements, and Reports

As a member or authorized finance leader,
I want permission-appropriate giving views,
So that I can verify personal contributions or oversee church funds without exposing other donors.

**Acceptance Criteria:**

**Given** an authenticated member and enabled member giving access
**When** the member views history or requests a statement
**Then** only contributions linked and approved for that member are shown for the selected period
**And** another member's records cannot be accessed by changing client parameters.

**Given** an authorized finance user
**When** giving reports are filtered by branch, category, campaign, status, or period
**Then** permitted totals and underlying records reconcile to confirmed contributions and adjustments
**And** donor identity is minimized or omitted unless the role explicitly permits it.

**Given** an unauthorized role, export, dashboard, search, log, or notification path
**When** giving information is requested or generated
**Then** financial values and identities are denied or redacted
**And** the access decision follows financial audit policy.

## Epic 12: Serve Each Role on Web and Mobile

Members, team leaders, branch administrators, and HQ leaders can use role-specific dashboards and approved Android/iOS hybrid workflows through the Laravel API.

### Story 12.1: Establish the Secure Vue Hybrid Application

As a mobile church user,
I want an installable Android and iOS application connected to the Laravel API,
So that I can use approved church services securely on my device.

**Acceptance Criteria:**

**Given** an architecture decision selects and versions the Vue hybrid runtime and native bridge, such as Ionic Vue with Capacitor
**When** the application foundation is built for supported Android and iOS versions
**Then** development and release builds start, render the church-branded accessible shell, and call versioned HTTPS API endpoints
**And** environment configuration and signing secrets are excluded from source control.

**Given** ShepardOne Identity and its approved mobile device-credential flow
**When** a user signs in, refreshes access, signs out, or the credential is revoked
**Then** credentials use platform secure storage and Identity-contract rotation or invalidation
**And** sensitive tokens are absent from logs, URLs, analytics, and ordinary client storage.

**Given** push, QR scanning, camera, file, deep-link, or notification permissions are needed
**When** the user reaches the related feature
**Then** contextual permission is requested and denial leaves a usable fallback where feasible
**And** no permission is requested before its purpose is clear.

**Given** intermittent connectivity or a recoverable API failure
**When** the user performs an offline-tolerant action
**Then** preserved input, pending synchronization, retry, conflict, and final status are communicated clearly
**And** unsupported offline actions fail before creating false success.

### Story 12.2: Use the Member Web and Mobile Dashboard

As a member,
I want one personal dashboard on web and mobile,
So that I can manage my church life and requests from anywhere.

**Acceptance Criteria:**

**Given** an authenticated member
**When** the dashboard opens
**Then** permitted profile, family, status, branch, pastor, groups, teams, schedule, assignments, attendance, enabled giving, welfare, messages, newsletters, prayer, and care actions are organized by priority
**And** unavailable modules are omitted or shown as unavailable rather than exposing empty unauthorized data.

**Given** loading, empty, error, offline, pending-sync, or session-expired conditions
**When** a dashboard section is rendered
**Then** accessible responsive states preserve context and provide a valid recovery action
**And** sensitive cached data is protected or removed according to session policy.

### Story 12.3: Use the Team-Leader Web and Mobile Dashboard

As a team leader,
I want to manage my team from web or mobile,
So that I can respond to roster, attendance, report, task, training, and follow-up needs promptly.

**Acceptance Criteria:**

**Given** an authenticated leader with assigned teams
**When** the leader opens the dashboard
**Then** scoped members, availability, attendance, upcoming services, rosters, assignments, reports, tasks, new members, follow-ups, training, notifications, and performance indicators are shown
**And** urgent and overdue actions are distinguishable without relying on color alone.

**Given** the leader acts on attendance, assignment response, roster, task, report, or follow-up through mobile
**When** the API accepts the action
**Then** the dashboard updates consistently across clients
**And** stale versions or permission changes produce a recoverable conflict rather than overwriting newer data.

### Story 12.4: Use the Branch Administrator Dashboard

As a branch administrator,
I want a branch-wide operational dashboard,
So that I can monitor growth, engagement, care, welfare, events, teams, and authorized giving.

**Acceptance Criteria:**

**Given** an authenticated branch administrator
**When** the dashboard opens for an assigned branch
**Then** permitted totals and trends for members, visitors, converts, attendance, teams, volunteers, welfare, care, events, giving, growth, and follow-up are shown with data freshness
**And** calculations exclude records outside the effective branch and sensitivity scope.

**Given** the administrator drills into a metric or changes period
**When** details load
**Then** the filtered records reconcile to the metric and preserve permission boundaries
**And** inaccessible aggregates cannot be inferred through counts or errors.

### Story 12.5: Use the HQ Leadership Dashboard

As an authorized HQ leader,
I want consolidated church-wide KPIs and branch comparisons,
So that I can make informed governance and ministry decisions.

**Acceptance Criteria:**

**Given** an authenticated HQ leader with church-wide analytical scope
**When** the dashboard opens
**Then** permitted membership, growth, attendance, visitors, converts, baptisms, team and volunteer participation, welfare, care, events, giving, and branch performance measures are consolidated with definitions and freshness
**And** restricted dimensions are suppressed or aggregated according to privacy policy.

**Given** the leader compares branches or drills into a KPI
**When** filters are applied
**Then** results use consistent metric definitions and reconcile to authorized source records
**And** small-group suppression or other disclosure controls prevent sensitive inference.

## Epic 13: Turn Church Data into Decisions

Authorized leaders can compose dashboards, run standard and custom reports, export results, and schedule permission-checked distribution.

### Story 13.1: Compose a Role-Specific Dashboard

As an authorized dashboard administrator,
I want to configure dashboards from approved widgets,
So that each role sees the measures and actions relevant to its work.

**Acceptance Criteria:**

**Given** an authorized administrator
**When** KPI, bar, line, pie, table, map, trend, demographic, team, attendance, or welfare widgets are selected, arranged, scoped, and assigned to roles
**Then** a previewable dashboard version can be published without code changes
**And** invalid metrics, inaccessible data sources, misleading visualization choices, and unsupported combinations are blocked or clearly warned.

**Given** a user opens an assigned dashboard
**When** widgets load
**Then** each widget enforces current row and field permissions, shows definition and freshness, and uses accessible responsive presentation
**And** one failed widget does not prevent other authorized widgets from loading.

### Story 13.2: Run Standard Church Reports

As an authorized church leader,
I want trusted standard reports,
So that recurring operational and management questions have consistent answers.

**Acceptance Criteria:**

**Given** a standard membership, attendance, team, welfare, care, communication, weekly, monthly, quarterly, or annual report
**When** an authorized user selects permitted branch, period, and supported filters
**Then** the report uses documented metric definitions and returns reconcilable results within the performance target
**And** restricted fields and small sensitive cohorts follow disclosure policy.

**Given** no matching data, stale source data, partial provider data, or a calculation failure
**When** the report runs
**Then** the limitation is shown instead of a misleading zero or complete result
**And** retry or support context is available without exposing internals.

### Story 13.3: Build a Custom Report Without Code

As a permitted report designer,
I want to select data, fields, filters, groups, sorts, and calculations,
So that I can answer new church questions without developer intervention.

**Acceptance Criteria:**

**Given** approved report data sources and metadata
**When** a designer selects fields and applies branch, date, age, gender, membership, team, department, group, attendance, event, welfare, or location filters plus grouping, sorting, and calculations
**Then** a permission-safe preview is generated and the definition can be saved and versioned
**And** unsafe joins, unrestricted sensitive fields, invalid formulas, and excessive query cost are blocked.

**Given** another authorized user runs a saved report
**When** effective permissions or source schema differ from the designer's context
**Then** current permissions are reapplied and incompatible fields produce a controlled report error
**And** the saved definition never grants access by itself.

### Story 13.4: Export Authorized Report Results

As an authorized report user,
I want to export results in an approved format,
So that I can share or analyze information outside the live view when permitted.

**Acceptance Criteria:**

**Given** a completed report and export permission
**When** PDF, Excel, CSV, print, dashboard, or email output is requested
**Then** the output preserves active filters, labels, generated time, data classification, and permitted fields
**And** spreadsheet injection, unsafe filenames, and unauthorized hidden data are prevented.

**Given** a large export
**When** generation exceeds the interactive threshold
**Then** it runs as a queued, retryable job and the requester receives secure time-limited access on completion
**And** generation, download, delivery, expiry, and failure are audited.

### Story 13.5: Schedule and Distribute Reports

As an authorized report owner,
I want reports generated and delivered on a recurring schedule,
So that leaders receive timely information without manual work.

**Acceptance Criteria:**

**Given** an approved report, schedule, output format, recipients, timezone, and delivery channel
**When** the schedule is saved
**Then** the system validates owner permission, recipient eligibility, data classification, and next run time
**And** invalid or overly broad distribution is rejected.

**Given** a scheduled run becomes due
**When** the report is generated
**Then** current owner and recipient permissions are checked before data generation and again before delivery
**And** revoked access, failed generation, or failed delivery prevents disclosure and creates an actionable log.

**Given** retry or scheduler duplication
**When** the same run identifier is processed
**Then** only one authorized distribution is completed
**And** delivery outcome and recipient list are auditable.

## Epic 14: Find and Govern Church Records

Users can securely search across church operations and manage versioned, categorized, permission-controlled documents.

### Story 14.1: Upload and Categorize Protected Documents

As an authorized church user,
I want to upload and categorize documents against relevant records,
So that approved evidence, materials, minutes, policies, and forms are stored consistently.

**Acceptance Criteria:**

**Given** an authorized user and a member, welfare, training, meeting, team, policy, form, event, or other supported record
**When** a permitted file with category, title, classification, retention, and access scope is uploaded
**Then** it is validated, malware-checked where feasible, stored outside the public application path or in protected object storage, and linked to the record
**And** filename, type, size, and content failures do not expose unsafe content.

**Given** a restricted parent record or document classification
**When** access scope is selected
**Then** the document cannot be made less restrictive than governing policy
**And** metadata, thumbnails, and processing jobs inherit the same protection.

### Story 14.2: Version, Retrieve, and Audit Documents

As an authorized document user,
I want to retrieve current documents and preserve prior versions,
So that the church can collaborate without losing history or access control.

**Acceptance Criteria:**

**Given** an existing document
**When** an authorized editor uploads a replacement
**Then** a new immutable version becomes current with actor, time, reason, checksum, and retained prior versions
**And** links to the document resolve according to the viewer's current permission.

**Given** an authorized user searches, previews, or downloads a document or version
**When** access is granted
**Then** content is delivered through a short-lived protected response with correct type and safe disposition
**And** the access is audited when required by classification.

**Given** an expired retention period, legal hold, archive request, or unauthorized deletion attempt
**When** lifecycle processing occurs
**Then** policy determines archive or deletion and preserves required evidence
**And** ordinary users cannot bypass holds or delete historical versions.

### Story 14.3: Search Church Records Within My Permissions

As an authorized user,
I want one search across church records,
So that I can find relevant people, families, branches, teams, groups, events, attendance, cases, reports, and documents quickly.

**Acceptance Criteria:**

**Given** an authenticated user enters a supported query
**When** global search runs
**Then** results from permitted record types are ranked and grouped with enough safe context to distinguish them
**And** typical queries meet the agreed two-second target under normal load.

**Given** a record or field is outside the user's branch, role, consent, sensitivity, or financial scope
**When** indexes are built or queried
**Then** the record or restricted field cannot appear in results, counts, snippets, suggestions, caches, or facets
**And** direct navigation independently rechecks authorization.

**Given** a record is changed, archived, consent-restricted, transferred, or deleted
**When** index synchronization completes or an immediate access check occurs
**Then** stale search data cannot grant access
**And** indexing failures are retryable and visible to authorized operators.

## Epic 15: Migrate, Integrate, and Operate Reliably

Administrators can migrate legacy data, connect approved external services through documented APIs, and monitor operational and security events.

### Story 15.1: Map, Clean, and Validate Legacy Data

As a data migration administrator,
I want to profile and map Excel, CSV, database, and membership-system data,
So that legacy records can be cleansed before entering production.

**Acceptance Criteria:**

**Given** an authorized administrator uploads or connects an approved source
**When** profiling runs
**Then** columns, types, counts, missing values, invalid formats, duplicates, and sensitive classifications are summarized without importing production records
**And** source files are protected by migration-specific access and retention policy.

**Given** a source profile
**When** fields, transformations, reference values, branch ownership, defaults, and duplicate rules are mapped
**Then** the mapping can be versioned and tested against a sample
**And** unmapped required data, invalid transformations, and ambiguous identity matches block approval.

**Given** a validation run
**When** cleansing and mapping complete
**Then** accepted, corrected, rejected, and duplicate-review records are reported with reasons
**And** no partial production changes occur.

### Story 15.2: Rehearse and Execute Migration Cutover

As a migration lead,
I want test migrations, reconciliation, approval, and controlled production cutover,
So that the church can go live with complete and trusted data.

**Acceptance Criteria:**

**Given** an approved mapping and sanitized target environment
**When** a test migration runs
**Then** records are imported idempotently with source lineage, duplicate handling, error report, count and financial reconciliation, and performance timing
**And** authorized church representatives can validate the result before sign-off.

**Given** approved UAT, final cleansing, backup, cutover plan, rollback criteria, and maintenance window
**When** production migration executes
**Then** import stages, reconciliation, verification, decision points, and operator actions are logged
**And** a failed acceptance threshold stops or rolls back according to plan.

**Given** successful production verification
**When** go-live is approved
**Then** source-to-target migration reports, unresolved exceptions, ownership, and hypercare monitoring are available
**And** migration credentials and temporary data are revoked or disposed according to policy.

### Story 15.3: Provide Protected and Documented REST APIs

As an approved integration developer,
I want versioned REST/JSON APIs with clear contracts,
So that external and hybrid clients can integrate predictably and securely.

**Acceptance Criteria:**

**Given** an approved API consumer using an Identity session, OIDC/OAuth credential, or typed machine principal as applicable
**When** a documented endpoint is called
**Then** authentication, branch scope, record authorization, validation, rate limit, and response schema are enforced
**And** errors use stable non-disclosing codes with a correlation reference.

**Given** an API version
**When** documentation is generated or published
**Then** endpoints, schemas, examples, scopes, pagination, filters, errors, rate limits, and deprecation policy match executable contracts
**And** contract tests detect incompatible undocumented changes.

**Given** an expired, revoked, over-limit, under-scoped, or malformed credential
**When** access is attempted
**Then** the request is denied without leaking record existence
**And** security-relevant events are logged without secrets.

### Story 15.4: Deliver Reliable Outbound Webhooks

As an integration administrator,
I want approved systems notified of selected church events,
So that connected services can respond without polling sensitive APIs.

**Acceptance Criteria:**

**Given** an authorized administrator
**When** an HTTPS endpoint, secret, allowed event types, scope, and status are configured and verified
**Then** the subscription is stored securely and can receive only approved event fields
**And** private care, prayer, welfare, identity, or finance payloads require explicit policy approval.

**Given** a subscribed event occurs
**When** delivery runs
**Then** a signed, uniquely identified, versioned payload is sent with timeout, retry, exponential backoff, and delivery history
**And** repeated attempts preserve idempotency information.

**Given** persistent failure, invalid endpoint behavior, secret rotation, or subscription revocation
**When** delivery is evaluated
**Then** delivery is paused or quarantined according to policy and operators are alerted
**And** revoked subscriptions receive no future events.

### Story 15.5: Configure Approved External Service Adapters

As a system integration administrator,
I want to configure approved email, SMS, push, payment, WhatsApp, website, livestream, accounting, identity, storage, and productivity adapters,
So that providers can change without rewriting church-domain workflows.

**Acceptance Criteria:**

**Given** an approved provider contract and adapter
**When** protected credentials, environment, mappings, quotas, callback URLs, and feature flags are configured
**Then** connectivity and a non-destructive health check can be tested
**And** secrets are encrypted, access-controlled, rotatable, and absent from logs.

**Given** a domain operation requests an external capability
**When** the active adapter processes it
**Then** provider-specific data is translated through the documented internal contract with correlation, idempotency, timeout, and retry behavior
**And** provider failure does not corrupt the originating church record.

**Given** a provider is replaced or disabled
**When** configuration changes
**Then** new operations use the effective adapter while historical provider references remain interpretable
**And** in-flight work follows an explicit drain, retry, or cancellation policy.

### Story 15.6: Monitor Operations, Backups, and Recovery

As a system administrator,
I want actionable visibility into application health, jobs, integrations, security, backups, and recovery readiness,
So that the church can meet availability and support commitments.

**Acceptance Criteria:**

**Given** production application, API, queue, scheduler, search, storage, database, integration, notification, and security components
**When** telemetry is emitted
**Then** authorized operators can view health, latency, error rate, queue depth, failed jobs, provider failures, capacity, and security alerts with correlation identifiers
**And** logs and metrics exclude prohibited personal data, credentials, and message content.

**Given** an agreed threshold or critical failure
**When** it is breached
**Then** the configured support channel receives a deduplicated alert with severity and runbook context
**And** acknowledgement and resolution timing can be measured against the SLA.

**Given** the production backup policy
**When** daily incremental, weekly full, off-site replication, encryption, and monitoring jobs run
**Then** completion, integrity, retention, and failure status are recorded
**And** failed or stale protection triggers an actionable alert.

**Given** a scheduled restoration or disaster-recovery exercise
**When** recovery is performed in an isolated target
**Then** data and service verification evidence measures actual RPO and RTO against the 1-hour and 4-hour targets
**And** findings, corrective actions, deployment steps, and approved runbooks are documented.