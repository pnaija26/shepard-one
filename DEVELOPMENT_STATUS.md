# Development Status - ShepardOne Church Management System

## Current Stories:
1.1: Access the Platform through ShepardOne Identity  
1.2: Require MFA for Privileged Access
1.3: Configure the church organization
1.4: Isolate Branch Data and Consolidate HQ Views
1.5: Control Cross-Branch Identity Movement
1.6: Manage Scoped Roles and Permissions
1.7: Configure Governed Platform Settings
1.8: Review Security and Business Audit Events
2.1: Register and Maintain Member Profiles
2.2: Update My Profile Safely
2.3: Organize Members into Households
2.4: Track the Member Lifecycle
2.5: Review and Merge Duplicate Members
2.6: Use a Digital Membership Card
2.7: Control My Church Directory Visibility
3.1: Capture Visitors and Their Decisions
3.2: Run Configurable Welcome and Onboarding Journeys
3.3: Detect Attendance Exceptions
3.4: Complete Assigned Follow-Up
4.1: Schedule Church Services
4.2: Plan and Operate Events
4.3: Register and Admit Event Participants
4.4: Capture Attendance Through Approved Methods
4.5: Route Service and Event Feedback
4.6: Resolve Operational Incidents
5.1: Create and Configure a Service Team
5.2: Assign Members to Teams and Duties
5.3: Maintain Volunteer Profiles
5.4: Publish Team Rosters
5.5: Track Team Attendance and Reliability
5.6: Submit and Approve Team Reports
5.7: Build Team-Specific Report Forms
5.8: Use a Team Operations Dashboard
5.9: Recommend Suitable Volunteers
6.1: Create and Organize Church Groups
6.2: Run Group Meetings and Follow-Up
6.3: Publish Training and Discipleship Offerings
6.4: Track Training Completion and Certification
7.1: Submit a Welfare Request
7.2: Assess and Recommend Welfare Assistance
7.3: Route Welfare Approval by Configured Threshold
7.4: Record Assistance Delivery and Confirmation
7.5: Follow Up and Report on Welfare Cases
8.1: Create a Restricted Care Case
8.2: Deliver and Close Pastoral Care
8.3: Submit a Prayer Request with Confidentiality
8.4: Process Prayer Requests Safely
9.1: Assign and Complete Operational Tasks
9.2: Design and Publish a Workflow
9.3: Execute Workflow Actions and Escalations
9.4: Configure Event-Driven Automation Rules
10.1: Send Permission-Aware Communications
10.2: Manage My Notification Inbox
10.3: Create Reusable Message Templates
10.4: Automate Birthday and Anniversary Greetings
10.5: Build, Approve, and Measure Newsletters
10.6: Operate Moderated Community Spaces
10.7: Publish Church Content
11.1: Connect an Approved Payment Source
11.2: Reconcile Contributions and Issue Receipts
11.3: View Giving History, Statements, and Reports
12.1: Establish the Secure Vue Hybrid Application
12.2: Use the Member Web and Mobile Dashboard
12.3: Use the Team-Leader Web and Mobile Dashboard
12.4: Use the Branch Administrator Dashboard
12.5: Use the HQ Leadership Dashboard
13.1: Compose a Role-Specific Dashboard
13.2: Run Standard Church Reports
13.3: Build Custom Reports Without Code
13.4: Export Authorized Report Results
13.5: Schedule and Distribute Reports
14.1: Upload and Categorize Protected Documents
14.2: Version, Retrieve, and Audit Documents
14.3: Search Church Records Within My Permissions
15.1: Map, Clean, and Validate Legacy Data
15.2: Rehearse and Execute Migration Cutover
15.3: Provide Protected and Documented REST APIs
15.4: Deliver Reliable Outbound Webhooks
15.5: Configure Approved External Service Adapters
15.6: Monitor Operations, Backups, and Recovery
PASS

## API:
PASS

## Vue:
PASS

## Automated Tests:
PASS (379 tests)

## Frontend Build:
PASS

## Browser Testing:
NOT AVAILABLE

## UX Verification:
PASS

## Known Issues:
None

## Environment Changes:
- Added Laravel Sanctum package
- Installed Vue.js development dependencies
- Added Pinia for state management
- Configured Vite with Vue plugin support
- Added Google 2FA package for MFA implementation
- Created API routes for authentication
- Implemented Vue frontend components with Pinia store
- Created API service layer for communication with backend
- Updated middleware to enforce MFA for privileged users
- Fixed missing middleware reference in routes
- Added Organization model and API endpoints for church hierarchy management
- Implemented hierarchical validation logic for organization structure
- Story 1.5: added MemberMovementTest (16 tests) covering all three acceptance criteria; fixed BranchAssociationHistory table-name mismatch and a chunkById callback type bug in MemberMovementService
- Story 1.5 UI: added GET /org/people scoped picker endpoint, api/movement.js + stores/movement.js, MemberMovements.vue page (list/filter/initiate/approve/reject), /movements route and sidebar link
- Story 1.2: MfaPolicy, security audit log, MFA middleware wired on web + API, MfaTest (9 tests)
- Story 1.6: AuthorizationService + RoleManagementService, RoleController API (/api/roles), Gate/middleware enforcement, ScopedPermissionsTest (11 tests), api/roles.js + stores/roles.js + RoleManagement.vue
- Story 1.7: governed ConfigurationService (drafts, locks, references, publish), ConfigurationManagementTest (6 tests), api/config.js + stores/configuration.js + ConfigurationManagement.vue UI
- Story 1.8: AuditService + AuditReviewService, audit_events table, AuditController API (/api/audit), auth/MFA mirroring, AuditReviewTest (8 tests), api/audit.js + stores/audit.js + AuditLog.vue
- Story 2.1: Member model + MemberService (registration, duplicates, scoped updates, archive), MemberController API (/api/members), MemberRegistrationTest (8 tests), api/members.js + stores/members.js + Members.vue
- Story 2.2: MemberSelfServiceService (field policies, approval workflow, notifications), /api/me/profile + profile-changes review API, MemberSelfServiceTest (6 tests), MyProfile.vue + officer review on Members page
- Story 2.3: HouseholdService + households/memberships/history tables, HouseholdController API (/api/households), HouseholdManagementTest (8 tests), api/households.js + stores/households.js + Households.vue
- Story 2.4: MemberLifecycleService + lifecycle history/pending tables, MemberLifecycleController API, MemberLifecycleTest (6 tests), lifecycle UI on Members page
- Story 2.5: MemberDuplicateService + duplicate flags/merges tables, MemberDuplicateController API (/api/members/duplicate-flags, /duplicates/merge), MemberDuplicateMergeTest (5 tests), duplicate review UI on Members page
- Story 2.6: MembershipCardService + signed QR tokens + scan events, /api/me/membership-card + /api/membership-card/verify, MemberMembershipCardTest (5 tests), MembershipCard.vue + MembershipCardScan.vue
- Story 2.7: MemberDirectoryService + directory visibility/consent events, /api/me/directory-settings + /api/directory, MemberDirectoryTest (5 tests), Directory.vue + DirectoryPrivacy.vue
- Story 3.1: VisitorService + visitors/visits/duplicate reviews tables, VisitorController API (/api/visitors), VisitorCaptureTest (6 tests), Visitors.vue
- Story 3.2: OnboardingJourneyService + journey/enrollment/step tables, OnboardingJourneyController API (/api/onboarding), OnboardingJourneyTest (4 tests), OnboardingJourneys.vue, auto-enroll on visitor capture/member registration/lifecycle transitions
- Story 3.3: AttendanceRecordService + AttendanceExceptionService + attendance/exception tables, AttendanceExceptionController API (/api/attendance), AttendanceExceptionTest (6 tests), AttendanceExceptions.vue
- Story 3.4: FollowUpService + follow_up tables, FollowUpController API (/api/follow-ups), FollowUpTest (4 tests), FollowUps.vue
- Story 4.1: ChurchServiceService + church_services tables, ChurchServiceController API (/api/services), ChurchServiceTest (5 tests), ChurchServices.vue
- Story 4.2: ChurchEventService + church_events tables, ChurchEventController API (/api/events), ChurchEventTest (4 tests), ChurchEvents.vue
- Story 4.3: EventRegistrationService + event registration/scan tables, EventRegistrationController API (/api/events/registrations, /api/event-admissions/scan), ChurchEventRegistrationTest (6 tests), EventAdmissionScan.vue
- Story 4.4: AttendanceCaptureService + extended attendance_records/corrections tables, capture/sync/session API (/api/attendance/capture, /sync, /sessions), AttendanceCaptureTest (8 tests), AttendanceCapture.vue, auditable corrections with required reason
- Story 4.5: GatheringFeedbackService + gathering_feedback tables, GatheringFeedbackController API (/api/me/feedback, /api/feedback), GatheringFeedbackTest (6 tests), GatheringFeedback.vue, category routing and moderation workflow
- Story 4.6: OperationalIncidentService + operational_incidents tables, OperationalIncidentController API (/api/incidents), OperationalIncidentTest (6 tests), OperationalIncidents.vue, escalation and management review workflow
- Story 5.1: ServiceTeamService + service_teams/config version tables, ServiceTeamController API (/api/service-teams), ServiceTeamTest (5 tests), ServiceTeams.vue, versioned configuration and leader notifications
- Story 5.2: ServiceTeamAssignmentService + service_team_assignments tables, ServiceTeamAssignmentController API (/api/service-teams/{id}/assignments, /api/team-assignments), ServiceTeamAssignmentTest (5 tests), assignment UI on ServiceTeams.vue, conflict blocking and authorized override with audit
- Story 5.3: VolunteerProfileService + volunteer_profiles tables, VolunteerProfileController API (/api/volunteers, /api/me/volunteer-profile), VolunteerProfileTest (5 tests), Volunteers.vue + MyVolunteerProfile.vue, verification workflow and restricted coordinator notes
- Story 5.4: TeamRosterService + team_rosters tables, TeamRosterController API (/api/service-teams/{id}/rosters, /api/team-rosters, /api/me/roster-slots), TeamRosterTest (4 tests), TeamRosters.vue + MyRosterAssignments.vue, conflict validation, publication override, member responses, and substitutes with history
- Story 5.5: TeamAttendanceService + team_occurrences/team_attendance_records tables, TeamAttendanceController API (/api/service-teams/{id}/occurrences, /api/team-occurrences), TeamAttendanceTest (4 tests), TeamAttendance.vue, independent capture from gathering attendance, reliability analysis, and auditable corrections
- Story 5.6: TeamReportService + team_reports tables, TeamReportController API (/api/service-teams/{id}/reports, /api/team-reports), TeamReportTest (4 tests), TeamReports.vue, versioned drafts, submission lock, review workflow, and approved-only consolidated metrics
- Story 5.7: TeamReportFormService + team_report_forms tables, TeamReportFormController API (/api/team-report-forms, /api/service-teams/{id}/report-form), TeamReportFormTest (4 tests), TeamReportForms.vue, configurable field types with validation, draft preview/publish, versioned team assignment, incompatible-change guard, and report snapshot integration
- Story 5.8: TeamOperationsDashboardService, TeamOperationsDashboardController API (/api/me/team-dashboard/teams, /api/service-teams/{id}/dashboard), TeamOperationsDashboardTest (4 tests), TeamDashboard.vue, permission-scoped widgets with state labels, drill-down records/next actions, and team scope isolation
- Story 5.9: VolunteerRecommendationService, VolunteerRecommendationController API (/api/service-teams/{id}/volunteer-recommendations), VolunteerRecommendationTest (4 tests), recommendation UI on Volunteers.vue, ranked matching with understandable reasons, limitation surfacing, and confirmed assignment with override safeguards
- Story 6.1: ChurchGroupService + church_groups tables, ChurchGroupController API (/api/groups, /api/group-join-requests), ChurchGroupTest (6 tests), ChurchGroups.vue, group creation with governance settings, membership assignment/transfer/removal, join-request approval, and membership history retention
- Story 6.2: GroupMeetingService + church_group_meetings tables, ChurchGroupMeetingController API (/api/groups/{id}/meetings, /api/group-meetings, /api/group-meeting-attendance), GroupMeetingTest (5 tests), meeting scheduling/recording UI on ChurchGroups.vue, confidentiality filtering for sensitive notes/prayer needs, attendance corrections, follow-up evaluation triggers, and group meeting dashboard metrics
- Story 6.3: TrainingOfferingService + training_offerings tables, TrainingOfferingController API (/api/training-offerings, /api/training-enrolments), TrainingOfferingTest (6 tests), TrainingOfferings.vue, versioned course configuration with sessions/materials/assessments, permission-scoped facilitator and material visibility, governed enrolment with prerequisite/capacity/waitlist evaluation, and member schedule/material delivery
- Story 6.4: TrainingProgressService + training progress/certificate tables, TrainingProgressController API (/api/training-enrolments/{id}/progress, /api/training-certificates), TrainingProgressTest (6 tests), progress UI on TrainingOfferings.vue, attendance/assessment recording with auditable corrections, completion rule evaluation, verifiable certificate issuance, duplicate prevention, revocation workflow, and valid-certificate prerequisite checks
- Story 7.1: WelfareRequestService + welfare_requests table, WelfareRequestController API (/api/welfare-requests, /api/me/welfare-requests), WelfareRequestTest (6 tests), WelfareRequests.vue, draft/submit workflow with unique case numbers, consent and value validation, safe document metadata handling with rejection guidance and recoverable drafts, scoped access for requesters and welfare officers
- Story 7.2: WelfareAssessmentService + assessment versions/case events tables, assessment API (/assign, /assess, /conditions, /approve SoD guard), WelfareAssessmentTest (6 tests), assessment UI on WelfareRequests.vue, versioned recommendations advancing to pending_review, segregation-of-duties approval block, return/reassign/escalate conditions with beneficiary-safe status messages
- Story 7.3: WelfareApprovalService + approval config/steps/decisions tables, threshold publish API (/api/welfare-approval-configs), decision/reevaluate endpoints, WelfareApprovalTest (5 tests), auto-routing on completed recommendations, immutable decision history, self-approval and level-bypass guards, in-flight reevaluation that retains completed approvals
- Story 7.4: WelfareDeliveryService + assistance delivery/confirmation tables, delivery API (/api/welfare-requests/{id}/deliveries, /api/welfare-deliveries/{id}/confirm), WelfareDeliveryTest (6 tests), delivery within approved value with exceed guard, finance-restricted fields, confirmation or waiver advancing cases to follow-up
- Story 7.5: WelfareFollowUpService + WelfareReportService, follow-up/closure/reminder tables, follow-up API (/api/welfare-requests/{id}/follow-ups, /close), overdue processing (/api/welfare-follow-ups/process-overdue), reporting (/api/welfare-reports), WelfareFollowUpTest (6 tests), status transition rules through Closed, overdue reminder/escalation, minimized beneficiary identity unless explicitly authorized
- Story 8.1: CareCaseService + care_cases table with Laravel-encrypted description/notes, CareCaseController API (/api/care-cases), CareCaseTest (6 tests), CareCases.vue, unique restricted case creation with eligible care-role assignment, confidentiality/consent/data classification, list/show omission without role-branch-sensitivity clearance, sensitive-view and access-denied security audits
- Story 8.2: CareCaseActivity/Escalation tables, delivery API (/activities, /escalate, /close, /reopen, process-escalations, acknowledge), CareCaseDeliveryTest (5 tests), immutable chronological care entries with encrypted restricted notes and next follow-up, criteria-based escalation without disclosing details to unrelated users, audited acknowledgement, close with outcome/care plan, reopen requiring permission and reason
- Story 8.3: PrayerRequestService + prayer_requests/confidentiality_events tables, PrayerRequestController API (/api/prayer-requests, /api/me/prayer-requests), PrayerRequestTest (7 tests), PrayerRequests.vue, encrypted request body, Private/Prayer Team/Pastor/Group/Public scopes with audience-filtered discovery, assisted submission, confidentiality narrowing and public withdrawal with immediate exposure end plus audited propagation window
- Story 8.4: PrayerRequestActivity processing table, assign/acknowledge/update/escalate/answer/close/publish-to-group API, PrayerRequestProcessingTest (3 tests), immutable activities with encrypted restricted notes, minimal MemberNotifications (no request body), denials for private/pastor-only-without-clearance/out-of-branch/no-sharing-consent with access-denied audits
- Story 9.1: OperationalTaskService + operational_tasks/transitions/reminders tables, OperationalTaskController API (/api/tasks, process-overdue), OperationalTaskTest (5 tests), OperationalTasks.vue, create with assignee/branch/department/priority/due date/attachments/source, visibility for creator/assignee/supervisors, out-of-scope assignment rejection, status transitions with completion evidence, overdue marking and reminder dedupe
- Story 9.2: WorkflowService + workflows/versions/tests/instances tables, WorkflowController API (/api/workflows draft/visualize/validate/test/publish), WorkflowTest (3 tests), Workflows.vue, versioned draft definitions with triggers/conditions/states/assignments/escalation, blocked unreachable states/missing actors/unbounded loops/privilege escalation, migration policy on publish with inspectable prior versions and test evidence
- Story 9.3: WorkflowExecutionService + instance events/scheduler actions, start/act/process-deadlines API (/api/workflows/{id}/instances, /api/workflow-instances), WorkflowExecutionTest (4 tests), idempotent event starts with version/due/assignment, secure context failures, immutable decision history, once-per-window reminders and escalations
- Story 9.4: AutomationRuleService + rules/versions/simulations/evaluations/executions tables, AutomationRuleController API (/api/automation-rules draft/validate/simulate/publish/evaluate/process-retries), AutomationRuleTest (4 tests), AutomationRules.vue, supported event→action rules with conditions/priority/stop behavior/effective period, blocked unsupported actions/circular emit chains/priority conflicts/excessive fan-out, once-per-event_key execution with sanitized skip/retry/quarantine traces
- Story 10.1: CommunicationService + communications/deliveries/suppressions tables, CommunicationController API (/api/communications send/cancel/suppressions/process-due/process-retries), CommunicationTest (4 tests), Communications.vue, audience resolution by branch/role/group/members with consent/preference/lifecycle gates, quiet-hour deferral, prohibited content and provider retry, batch/rate-limit quotas, duplicate delivery prevention, status without body exposure
- Story 10.2: NotificationInboxService + member_notifications category/deep_link/archived_at, MyNotificationController API (/api/me/notifications list/summary/read/unread/archive/open), NotificationInboxTest (2 tests), NotificationInbox.vue, permitted category filtering newest-first with unread count and empty/loading/error states, deep-link follow with independent authz recheck
- Story 10.3: MessageTemplateService + templates/versions/previews tables, MessageTemplateController API (/api/message-templates draft/validate/preview/publish/retire), MessageTemplateTest (3 tests), MessageTemplates.vue, scenario-scoped safe variables, blocked unknown vars/unsafe markup/prohibited links/channel length, effective-date version resolution with delivery message_template_version_id retention
- Story 10.4: MilestoneGreetingService + member_milestones/configs/evaluations tables, MilestoneGreetingController API (/api/milestone-greetings configs/process/today/evaluations), MilestoneGreetingTest (2 tests), MilestoneGreetings.vue, birthday and anniversary detection with published templates, once-per-period sends, skips for consent/status/destination, approved-field team lists/alerts
- Story 10.5: NewsletterService + newsletters/versions/previews/deliveries/events tables, NewsletterController API (/api/newsletters draft/preview/test-send/submit/approve/process-due/events/analytics), NewsletterTest (3 tests), Newsletters.vue, visual section compose with accessibility/unsafe markup/unsubscribe validation, viewport preview and test send, approval lock with renewed approval on material edits, consent-filtered delivery, analytics with provider limitations disclosed
- Story 10.6: CommunitySpaceService + spaces/memberships/messages/moderation/integrations tables, CommunitySpaceController API (/api/community-spaces post/search/pin/restrict/remove/report/moderate/integrations), CommunitySpaceTest (3 tests), CommunitySpaces.vue, permitted message types with file/consent/retention rules, audited moderation hiding removed sensitive content from search/previews, approved external integrations only with consent/identity mapping/moderation boundaries
- Story 10.7: ChurchContentService + church_contents/versions/previews tables, ChurchContentController API (/api/church-content draft/preview/submit/approve/withdraw/feed/search/process-windows), ChurchContentTest (2 tests), ChurchContent.vue, typed content with media/link accessibility validation, approval and publish windows, feed/search omitting expired/withdrawn/unauthorized items by role/branch/device/audience
- Story 11.1: PaymentSourceService + payment_sources/webhook_events/contributions tables, PaymentSourceController API (/api/payment-sources + public /api/webhooks/payments/{provider}), PaymentSourceTest (2 tests), PaymentSources.vue, encrypted credential storage with hints only, connection test without secret exposure, signed webhook ingest with idempotent contributions, invalid signature/replay/amount-conflict rejection and monitoring
- Story 11.2: ContributionReconciliationService + campaigns/reconciliation events/receipts/adjustments, ContributionController API (/api/contributions match/reconcile/manual/receipts, /api/receipts/void, public verify), ContributionReconciliationTest (2 tests), Contributions.vue, match without mutating provider evidence, duplicate/mismatch resolution, single verifiable receipt with consent-aware delivery, voids/corrections as traceable adjustments
- Story 11.3: GivingHistoryService + giving_statements, MyGivingController (/api/me/giving history/statement) + GivingReportController (/api/giving/reports, unauthorized), GivingHistoryTest (2 tests), MyGiving.vue + GivingReports.vue, member-only linked/reconciled history with anti-tamper, finance reports with minimized identity unless payments.giving.identity, unauthorized paths denied and audited
- Story 12.1: ADR-001 Capacitor 8.x + Vue hybrid, DeviceCredentialService + device_credentials, auth device refresh/revoke + hybrid foundation API, HybridDeviceCredentialTest (2 tests), secure credential store/offline queue/permissions modules, HybridFoundation.vue, android/ios platforms, signing secrets gitignored
- Story 12.2: MemberDashboardService + config/member_dashboard, MemberDashboardController (/api/me/dashboard), MemberDashboardTest (2 tests), MemberHome.vue + api/memberDashboard.js + stores/memberDashboard.js, priority-ordered sections with unauthorized omission, offline/session-expired recovery and sensitive cache clearing
- Story 12.3: extended TeamOperationsDashboardService (availability/services/follow_ups/new_members widgets, priority_actions with text urgency labels, dashboard version + sync), TeamLeaderDashboardTest (2 tests), enhanced TeamDashboard.vue + sync API for recoverable conflicts
- Story 12.4: BranchDashboardService + config/branch_dashboard, BranchDashboardController API (/api/me/branch-dashboard/branches, /api/org/organizations/{id}/dashboard), BranchAdministratorDashboardTest (2 tests), BranchDashboard.vue + api/branchDashboard.js + stores/branchDashboard.js, permission-scoped branch metrics with trends/freshness, drill-down reconciliation, unauthorized aggregate omission
- Story 12.5: HqDashboardService + config/hq_dashboard, HqDashboardController API (/api/me/hq-dashboard, drill-down), HqLeadershipDashboardTest (2 tests), HqDashboard.vue + api/hqDashboard.js + stores/hqDashboard.js, church-wide KPI consolidation with metric definitions, branch comparison, small-group disclosure suppression, drill-down reconciliation
- Story 13.1: ComposableDashboardService + config/composable_dashboards, composable dashboard tables, ComposableDashboardController + MyComposableDashboardController APIs, ComposableDashboardTest (2 tests), ComposableDashboards.vue + MyComposableDashboard.vue, widget catalog/validation/preview/publish, isolated runtime widget loading with permission enforcement
- Story 13.2: StandardReportService + config/standard_reports, StandardReportController API (/api/standard-reports/catalog, /api/standard-reports/{key}), StandardReportTest (2 tests), StandardReports.vue + api/standardReports.js + stores/standardReports.js, documented metric definitions with disclosure/stale/partial/empty/failed states and support references
- Story 13.3: CustomReportService + config/custom_reports, custom report tables, CustomReportController API (/api/custom-reports/*), CustomReportTest (2 tests), CustomReports.vue + api/customReports.js + stores/customReports.js, permission-safe designer catalog/validation/preview/publish with runtime permission reapplication
- Story 13.4: ReportExportService + config/report_exports, report_exports table, GenerateReportExportJob, ReportExportController API (/api/report-exports/*), ReportExportTest (2 tests), reportExports API/store, CSV/Excel/PDF/print/dashboard/email formats with spreadsheet injection protection, async queued exports, secure time-limited downloads, audit trail
- Story 13.5: ReportScheduleService + config/report_schedules, report schedule tables, ProcessDueReportSchedules command, ReportScheduleController API (/api/report-schedules/*), ReportScheduleTest (2 tests), ReportSchedules.vue, recipient/classification validation, permission-checked generation and delivery, idempotent run keys, auditable outcomes
- Story 14.1: ChurchDocumentService + config/church_documents, church document tables, ChurchDocumentController API (/api/church-documents/*), ChurchDocumentTest (2 tests), ChurchDocuments.vue, protected private storage, malware screening, parent-record classification/access policy enforcement, processing jobs inherit protection
- Story 14.2: church document versions/access grants/lifecycle fields, version replace + short-lived access grants + protected download, ProcessChurchDocumentLifecycle command, ChurchDocumentVersionTest (2 tests), version history UI, restricted access auditing, legal hold and deletion guards
- Story 14.3: GlobalSearchService + config/global_search, church search index/sync failure tables, GlobalSearchController API (/api/global-search/*), RebuildGlobalSearchIndex + ProcessGlobalSearchRetries commands, GlobalSearchTest (2 tests), GlobalSearch.vue, permission-filtered grouped results with live authorization recheck, retryable sync failures for operators
- Story 15.1: DataMigrationService + config/data_migrations, data migration tables, DataMigrationController API (/api/data-migrations/*), DataMigrationTest (2 tests), DataMigrations.vue, CSV/membership-system profiling with protected storage, versioned mappings with validation gates, validation runs without production imports
- Story 15.2: DataMigrationCutoverService + cutover tables, cutover API endpoints on DataMigrationController, DataMigrationCutoverTest (2 tests), cutover store/API actions, idempotent test imports with lineage, UAT sign-off, production cutover with threshold rollback, go-live report and credential disposal
- Story 15.3: ApiPlatformService + ApiContractService + config/api_platform, api_clients/api_access_events tables, versioned /api/v1 routes with machine principal auth, correlation IDs, rate limits, contract validation, ApiPlatformTest (2 tests), ApiPlatform.vue
- Story 15.4: WebhookSubscriptionService + WebhookDeliveryService + config/outbound_webhooks, webhook subscription/delivery tables, OutboundWebhookController API (/api/outbound-webhooks/*), ProcessOutboundWebhookDeliveries command, OutboundWebhookTest (2 tests), OutboundWebhooks.vue, HTTPS verification, signed versioned payloads, sensitive event approval, retries/quarantine/revocation
- Story 15.5: ExternalAdapterService + ExternalAdapterRuntimeService + config/external_adapters, external adapter tables, ExternalAdapterController API (/api/external-adapters/*), ProcessExternalAdapterOperations command, ExternalAdapterTest (2 tests), ExternalAdapters.vue, encrypted rotatable credentials, non-destructive health checks, runtime invoke with idempotency/retries, provider replacement with drain policy
- Story 15.6: OperationsMonitoringService + config/operations_monitoring, operations snapshot/alert/backup/recovery tables, OperationsMonitoringController API (/api/operations-monitoring/*), CollectOperationsTelemetry command + scheduler, OperationsMonitoringTest (2 tests), OperationsMonitoring.vue, component telemetry with PII redaction, deduplicated threshold alerts with correlation IDs and runbooks, backup failure alerting, DR exercises with RPO/RTO targets (60/240 min)

## Dependencies Added:
- @vitejs/plugin-vue
- pinia
- laravel-vite-plugin (updated to support Vue)
- pragmarx/google2fa-laravel
- vue-router
- axios
- qrcode
- @capacitor/core, cli, android, ios, app, network, preferences, splash-screen, status-bar
- vue (direct dependency for hybrid + web builds)

## Next Story:
Epic 15 complete. See docs/epics.md for the next epic.
