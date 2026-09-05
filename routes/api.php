<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Api\MemberMovementController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ConfigurationController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MemberDuplicateController;
use App\Http\Controllers\Api\MemberLifecycleController;
use App\Http\Controllers\Api\MemberProfileChangeController;
use App\Http\Controllers\Api\MembershipCardVerifyController;
use App\Http\Controllers\Api\DirectoryController;
use App\Http\Controllers\Api\MyDirectorySettingsController;
use App\Http\Controllers\Api\MyMembershipCardController;
use App\Http\Controllers\Api\MyProfileController;
use App\Http\Controllers\Api\MyNotificationController;
use App\Http\Controllers\Api\OnboardingJourneyController;
use App\Http\Controllers\Api\AttendanceRecordController;
use App\Http\Controllers\Api\AttendanceExceptionController;
use App\Http\Controllers\Api\FollowUpController;
use App\Http\Controllers\Api\ChurchServiceController;
use App\Http\Controllers\Api\ChurchEventController;
use App\Http\Controllers\Api\EventRegistrationController;
use App\Http\Controllers\Api\GatheringFeedbackController;
use App\Http\Controllers\Api\OperationalIncidentController;
use App\Http\Controllers\Api\ServiceTeamAssignmentController;
use App\Http\Controllers\Api\ServiceTeamController;
use App\Http\Controllers\Api\TeamRosterController;
use App\Http\Controllers\Api\TeamReportController;
use App\Http\Controllers\Api\TeamReportFormController;
use App\Http\Controllers\Api\TeamOperationsDashboardController;
use App\Http\Controllers\Api\TeamAttendanceController;
use App\Http\Controllers\Api\MyRosterSlotController;
use App\Http\Controllers\Api\ChurchGroupController;
use App\Http\Controllers\Api\ChurchGroupMeetingController;
use App\Http\Controllers\Api\TrainingOfferingController;
use App\Http\Controllers\Api\TrainingProgressController;
use App\Http\Controllers\Api\WelfareRequestController;
use App\Http\Controllers\Api\WelfareApprovalController;
use App\Http\Controllers\Api\WelfareDeliveryController;
use App\Http\Controllers\Api\WelfareFollowUpController;
use App\Http\Controllers\Api\CareCaseController;
use App\Http\Controllers\Api\PrayerRequestController;
use App\Http\Controllers\Api\OperationalTaskController;
use App\Http\Controllers\Api\WorkflowController;
use App\Http\Controllers\Api\WorkflowInstanceController;
use App\Http\Controllers\Api\AutomationRuleController;
use App\Http\Controllers\Api\CommunicationController;
use App\Http\Controllers\Api\MessageTemplateController;
use App\Http\Controllers\Api\MilestoneGreetingController;
use App\Http\Controllers\Api\NewsletterController;
use App\Http\Controllers\Api\CommunitySpaceController;
use App\Http\Controllers\Api\ChurchContentController;
use App\Http\Controllers\Api\PaymentSourceController;
use App\Http\Controllers\Api\ContributionController;
use App\Http\Controllers\Api\MemberDashboardController;
use App\Http\Controllers\Api\BranchDashboardController;
use App\Http\Controllers\Api\HqDashboardController;
use App\Http\Controllers\Api\ComposableDashboardController;
use App\Http\Controllers\Api\MyComposableDashboardController;
use App\Http\Controllers\Api\StandardReportController;
use App\Http\Controllers\Api\CustomReportController;
use App\Http\Controllers\Api\ReportExportController;
use App\Http\Controllers\Api\ReportScheduleController;
use App\Http\Controllers\Api\ChurchDocumentController;
use App\Http\Controllers\Api\GlobalSearchController;
use App\Http\Controllers\Api\ApiPlatformController;
use App\Http\Controllers\Api\OutboundWebhookController;
use App\Http\Controllers\Api\ExternalAdapterController;
use App\Http\Controllers\Api\OperationsMonitoringController;
use App\Http\Controllers\Api\DataMigrationController;
use App\Http\Controllers\Api\MyGivingController;
use App\Http\Controllers\Api\GivingReportController;
use App\Http\Controllers\Api\VolunteerRecommendationController;
use App\Http\Controllers\Api\VolunteerProfileController;
use App\Http\Controllers\Api\MyVolunteerProfileController;
use App\Http\Controllers\Api\VisitorController;
use App\Http\Controllers\Api\RoleController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/device/refresh', [AuthController::class, 'refreshDevice']);
    Route::get('/hybrid/foundation', [AuthController::class, 'hybridFoundation']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/device/revoke', [AuthController::class, 'revokeDevice']);
    });
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('org')->group(function () {
    Route::get('organizations/{organization}/dashboard', [BranchDashboardController::class, 'show']);
    Route::get('organizations/{organization}/dashboard/drill-down/{metric}', [BranchDashboardController::class, 'drillDown']);
    Route::apiResource('organizations', OrganizationController::class);

    // Story 1.5: cross-branch identity movement (pending -> approved/rejected,
    // applied on the effective date). Scope enforced server-side per request.
    Route::get('/movements', [MemberMovementController::class, 'index']);
    Route::post('/movements', [MemberMovementController::class, 'store']);
    Route::get('/people', [MemberMovementController::class, 'people']);
    Route::get('/movements/{movement}', [MemberMovementController::class, 'show']);
    Route::post('/movements/{movement}/approve', [MemberMovementController::class, 'approve']);
    Route::post('/movements/{movement}/reject', [MemberMovementController::class, 'reject']);
});

// Story 1.6: scoped roles and permissions
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('roles')->group(function () {
    Route::get('/', [RoleController::class, 'index']);
    Route::post('/', [RoleController::class, 'store']);
    Route::get('/{role}', [RoleController::class, 'show']);
    Route::put('/{role}', [RoleController::class, 'update']);
    Route::delete('/{role}', [RoleController::class, 'destroy']);
    Route::post('/{role}/assignments', [RoleController::class, 'assign']);
    Route::delete('/{role}/assignments/{user}', [RoleController::class, 'revokeAssignment']);
});

// MFA routes for API
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/mfa/setup', [MfaController::class, 'setup']);
    Route::post('/mfa/verify', [MfaController::class, 'verify']);
});

// Configuration routes (Story 1.7) — literal paths before /{key}
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('config')->group(function () {
    Route::get('/categories', [ConfigurationController::class, 'categories']);
    Route::post('/categories', [ConfigurationController::class, 'createCategory']);
    Route::delete('/categories/{name}', [ConfigurationController::class, 'deleteCategory']);

    Route::get('/', [ConfigurationController::class, 'index']);
    Route::post('/', [ConfigurationController::class, 'store']);
    Route::get('/{key}', [ConfigurationController::class, 'show']);
    Route::put('/{key}', [ConfigurationController::class, 'update']);
    Route::post('/{key}/publish', [ConfigurationController::class, 'publish']);
    Route::delete('/{key}', [ConfigurationController::class, 'destroy']);
});

// Story 1.8: audit review — literal /export before /{id}
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('audit')->group(function () {
    Route::get('/export', [AuditController::class, 'export']);
    Route::get('/', [AuditController::class, 'index']);
    Route::get('/{id}', [AuditController::class, 'show'])->whereNumber('id');
});

// Story 2.2: member self-service (no MFA required for non-privileged members)
// Story 10.2: notification inbox under the same /me prefix
Route::middleware(['auth:sanctum'])->prefix('me')->group(function () {
    Route::get('/dashboard', [MemberDashboardController::class, 'show']);
    Route::get('/profile', [MyProfileController::class, 'show']);
    Route::put('/profile', [MyProfileController::class, 'update']);
    Route::get('/membership-card', [MyMembershipCardController::class, 'show']);
    Route::get('/directory-settings', [MyDirectorySettingsController::class, 'show']);
    Route::put('/directory-settings', [MyDirectorySettingsController::class, 'update']);
    Route::post('/feedback', [GatheringFeedbackController::class, 'store']);
    Route::get('/volunteer-profile', [MyVolunteerProfileController::class, 'show']);
    Route::put('/volunteer-profile', [MyVolunteerProfileController::class, 'update']);
    Route::get('/roster-slots', [MyRosterSlotController::class, 'index']);
    Route::post('/roster-slots/{slot}/respond', [MyRosterSlotController::class, 'respond']);

    Route::get('/notifications/summary', [MyNotificationController::class, 'summary']);
    Route::post('/notifications/mark-all-read', [MyNotificationController::class, 'markAllRead']);
    Route::get('/notifications', [MyNotificationController::class, 'index']);
    Route::get('/notifications/{memberNotification}', [MyNotificationController::class, 'show']);
    Route::post('/notifications/{memberNotification}/read', [MyNotificationController::class, 'markRead']);
    Route::post('/notifications/{memberNotification}/unread', [MyNotificationController::class, 'markUnread']);
    Route::post('/notifications/{memberNotification}/archive', [MyNotificationController::class, 'archive']);
    Route::post('/notifications/{memberNotification}/unarchive', [MyNotificationController::class, 'unarchive']);
    Route::post('/notifications/{memberNotification}/open', [MyNotificationController::class, 'open']);

    // Story 11.3: member giving history / statements
    Route::get('/giving', [MyGivingController::class, 'history']);
    Route::post('/giving/statement', [MyGivingController::class, 'statement']);
});

// Story 2.7: privacy-filtered directory (authenticated users with directory.read)
Route::middleware(['auth:sanctum'])->prefix('directory')->group(function () {
    Route::get('/export', [DirectoryController::class, 'export']);
    Route::get('/', [DirectoryController::class, 'index']);
    Route::get('/{member}', [DirectoryController::class, 'show']);
});

// Story 2.6: membership card verification (privileged scanners)
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('membership-card')->group(function () {
    Route::get('/purposes', [MembershipCardVerifyController::class, 'purposes']);
    Route::post('/verify', [MembershipCardVerifyController::class, 'verify']);
});

// Story 2.1: member profiles
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('members')->group(function () {
    Route::get('/profile-changes', [MemberProfileChangeController::class, 'index']);
    Route::post('/profile-changes/{changeRequest}/approve', [MemberProfileChangeController::class, 'approve']);
    Route::post('/profile-changes/{changeRequest}/reject', [MemberProfileChangeController::class, 'reject']);

    Route::get('/lifecycle/pending', [MemberLifecycleController::class, 'pendingIndex']);
    Route::post('/lifecycle/pending/{pending}/approve', [MemberLifecycleController::class, 'approvePending']);
    Route::post('/lifecycle/pending/{pending}/reject', [MemberLifecycleController::class, 'rejectPending']);

    Route::get('/duplicate-flags', [MemberDuplicateController::class, 'index']);
    Route::get('/duplicate-flags/{flag}', [MemberDuplicateController::class, 'show']);
    Route::post('/duplicate-flags/{flag}/dismiss', [MemberDuplicateController::class, 'dismiss']);
    Route::post('/duplicates/merge', [MemberDuplicateController::class, 'merge']);
    Route::post('/{member}/scan-duplicates', [MemberDuplicateController::class, 'scan']);

    Route::get('/', [MemberController::class, 'index']);
    Route::post('/', [MemberController::class, 'store']);
    Route::get('/{member}', [MemberController::class, 'show']);
    Route::get('/{member}/lifecycle', [MemberLifecycleController::class, 'show']);
    Route::post('/{member}/lifecycle/transition', [MemberLifecycleController::class, 'transition']);
    Route::put('/{member}', [MemberController::class, 'update']);
    Route::post('/{member}/archive', [MemberController::class, 'archive']);
});

// Story 4.3: event registration and admission
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->group(function () {
    Route::post('/event-admissions/scan', [EventRegistrationController::class, 'scan']);
    Route::get('/events/{churchEvent}/registrations', [EventRegistrationController::class, 'index']);
    Route::post('/events/{churchEvent}/registrations', [EventRegistrationController::class, 'store']);
});

// Story 4.2: church events
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('events')->group(function () {
    Route::get('/', [ChurchEventController::class, 'index']);
    Route::post('/', [ChurchEventController::class, 'store']);
    Route::get('/{churchEvent}', [ChurchEventController::class, 'show']);
    Route::put('/{churchEvent}', [ChurchEventController::class, 'update']);
    Route::post('/{churchEvent}/publish', [ChurchEventController::class, 'publish']);
    Route::post('/{churchEvent}/postpone', [ChurchEventController::class, 'postpone']);
    Route::post('/{churchEvent}/cancel', [ChurchEventController::class, 'cancel']);
    Route::post('/{churchEvent}/complete', [ChurchEventController::class, 'complete']);
    Route::post('/{churchEvent}/close', [ChurchEventController::class, 'close']);
});

// Story 4.1: church services
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('services')->group(function () {
    Route::get('/', [ChurchServiceController::class, 'index']);
    Route::post('/', [ChurchServiceController::class, 'store']);
    Route::get('/{churchService}', [ChurchServiceController::class, 'show']);
    Route::put('/{churchService}', [ChurchServiceController::class, 'update']);
    Route::post('/{churchService}/publish', [ChurchServiceController::class, 'publish']);
    Route::post('/{churchService}/cancel', [ChurchServiceController::class, 'cancel']);
});

// Story 3.4: follow-up tasks
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('follow-ups')->group(function () {
    Route::post('/process-escalations', [FollowUpController::class, 'processEscalations']);
    Route::get('/', [FollowUpController::class, 'index']);
    Route::post('/', [FollowUpController::class, 'store']);
    Route::get('/{followUp}', [FollowUpController::class, 'show']);
    Route::post('/{followUp}/activities', [FollowUpController::class, 'recordActivity']);
});

// Story 5.1: service teams
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('service-teams')->group(function () {
    Route::get('/', [ServiceTeamController::class, 'index']);
    Route::post('/', [ServiceTeamController::class, 'store']);
    Route::get('/{serviceTeam}', [ServiceTeamController::class, 'show']);
    Route::put('/{serviceTeam}', [ServiceTeamController::class, 'update']);
    Route::post('/{serviceTeam}/activate', [ServiceTeamController::class, 'activate']);
    Route::post('/{serviceTeam}/archive', [ServiceTeamController::class, 'archive']);
    Route::post('/{serviceTeam}/volunteer-recommendations', [VolunteerRecommendationController::class, 'recommend']);
    Route::post('/{serviceTeam}/volunteer-recommendations/confirm', [VolunteerRecommendationController::class, 'confirm']);
    Route::get('/{serviceTeam}/assignments', [ServiceTeamAssignmentController::class, 'index']);
    Route::post('/{serviceTeam}/assignments', [ServiceTeamAssignmentController::class, 'store']);
    Route::post('/{serviceTeam}/assignments/bulk', [ServiceTeamAssignmentController::class, 'bulkStore']);
    Route::get('/{serviceTeam}/rosters', [TeamRosterController::class, 'index']);
    Route::post('/{serviceTeam}/rosters', [TeamRosterController::class, 'store']);
    Route::get('/{serviceTeam}/occurrences', [TeamAttendanceController::class, 'listOccurrences']);
    Route::post('/{serviceTeam}/occurrences', [TeamAttendanceController::class, 'createOccurrence']);
    Route::get('/{serviceTeam}/attendance-analysis', [TeamAttendanceController::class, 'analyze']);
    Route::get('/{serviceTeam}/reports', [TeamReportController::class, 'index']);
    Route::post('/{serviceTeam}/reports', [TeamReportController::class, 'store']);
    Route::get('/{serviceTeam}/report-metrics', [TeamReportController::class, 'metrics']);
    Route::get('/{serviceTeam}/report-form', [TeamReportFormController::class, 'teamForm']);
    Route::get('/{serviceTeam}/dashboard', [TeamOperationsDashboardController::class, 'show']);
    Route::post('/{serviceTeam}/dashboard/sync', [TeamOperationsDashboardController::class, 'sync']);
    Route::get('/{serviceTeam}/dashboard/drill-down/{widget}', [TeamOperationsDashboardController::class, 'drillDown']);
});

// Story 5.4: team rosters
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('team-rosters')->group(function () {
    Route::get('/{teamRoster}', [TeamRosterController::class, 'show']);
    Route::post('/{teamRoster}/slots', [TeamRosterController::class, 'addSlot']);
    Route::post('/{teamRoster}/validate', [TeamRosterController::class, 'validateRoster']);
    Route::post('/{teamRoster}/publish', [TeamRosterController::class, 'publish']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('roster-slots')->group(function () {
    Route::post('/{slot}/substitute', [TeamRosterController::class, 'substitute']);
});

// Story 5.5: team attendance
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('team-occurrences')->group(function () {
    Route::get('/{occurrence}', [TeamAttendanceController::class, 'showOccurrence']);
    Route::post('/{occurrence}/attendance', [TeamAttendanceController::class, 'capture']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('team-attendance')->group(function () {
    Route::post('/{record}/correct', [TeamAttendanceController::class, 'correct']);
});

// Story 5.6: team reports
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('team-reports')->group(function () {
    Route::get('/{teamReport}', [TeamReportController::class, 'show']);
    Route::put('/{teamReport}', [TeamReportController::class, 'update']);
    Route::post('/{teamReport}/submit', [TeamReportController::class, 'submit']);
    Route::post('/{teamReport}/review', [TeamReportController::class, 'review']);
});

// Story 5.8: team operations dashboard
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('me/team-dashboard')->group(function () {
    Route::get('/teams', [TeamOperationsDashboardController::class, 'teams']);
});

// Story 12.4: branch administrator dashboard
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('me/branch-dashboard')->group(function () {
    Route::get('/branches', [BranchDashboardController::class, 'branches']);
});

// Story 12.5: HQ leadership dashboard
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('me/hq-dashboard')->group(function () {
    Route::get('/', [HqDashboardController::class, 'show']);
    Route::get('/drill-down/{metric}', [HqDashboardController::class, 'drillDown']);
});

// Story 13.1: composable role-specific dashboards
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('composable-dashboards')->group(function () {
    Route::get('/catalog', [ComposableDashboardController::class, 'catalog']);
    Route::get('/', [ComposableDashboardController::class, 'index']);
    Route::post('/', [ComposableDashboardController::class, 'store']);
    Route::get('/{composableDashboard}', [ComposableDashboardController::class, 'show']);
    Route::put('/{composableDashboard}/draft', [ComposableDashboardController::class, 'updateDraft']);
    Route::post('/{composableDashboard}/validate', [ComposableDashboardController::class, 'validateDefinition']);
    Route::post('/{composableDashboard}/preview', [ComposableDashboardController::class, 'preview']);
    Route::post('/{composableDashboard}/publish', [ComposableDashboardController::class, 'publish']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('me/composable-dashboard')->group(function () {
    Route::get('/', [MyComposableDashboardController::class, 'show']);
});

// Story 13.2: standard church reports
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('standard-reports')->group(function () {
    Route::get('/catalog', [StandardReportController::class, 'catalog']);
    Route::get('/{reportKey}', [StandardReportController::class, 'run']);
});

// Story 13.3: custom reports without code
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('custom-reports')->group(function () {
    Route::get('/catalog', [CustomReportController::class, 'catalog']);
    Route::get('/', [CustomReportController::class, 'index']);
    Route::post('/', [CustomReportController::class, 'store']);
    Route::get('/{customReport}', [CustomReportController::class, 'show']);
    Route::put('/{customReport}/draft', [CustomReportController::class, 'updateDraft']);
    Route::post('/{customReport}/validate', [CustomReportController::class, 'validateDefinition']);
    Route::post('/{customReport}/preview', [CustomReportController::class, 'preview']);
    Route::post('/{customReport}/publish', [CustomReportController::class, 'publish']);
    Route::get('/{customReport}/run', [CustomReportController::class, 'run']);
});

// Story 13.4: export authorized report results
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('report-exports')->group(function () {
    Route::get('/catalog', [ReportExportController::class, 'catalog']);
    Route::post('/', [ReportExportController::class, 'store']);
    Route::get('/{reference}/status', [ReportExportController::class, 'status']);
    Route::get('/{reference}/download', [ReportExportController::class, 'download']);
});

// Story 13.5: schedule and distribute reports
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('report-schedules')->group(function () {
    Route::get('/catalog', [ReportScheduleController::class, 'catalog']);
    Route::get('/', [ReportScheduleController::class, 'index']);
    Route::post('/', [ReportScheduleController::class, 'store']);
    Route::get('/{reportSchedule}', [ReportScheduleController::class, 'show']);
});

// Story 14.1: upload and categorize protected documents
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('church-documents')->group(function () {
    Route::get('/catalog', [ChurchDocumentController::class, 'catalog']);
    Route::get('/reference/{reference}', [ChurchDocumentController::class, 'showByReference']);
    Route::get('/', [ChurchDocumentController::class, 'index']);
    Route::post('/', [ChurchDocumentController::class, 'store']);
    Route::get('/{churchDocument}', [ChurchDocumentController::class, 'show']);
    Route::get('/{churchDocument}/versions', [ChurchDocumentController::class, 'versions']);
    Route::post('/{churchDocument}/versions', [ChurchDocumentController::class, 'replaceVersion']);
    Route::post('/{churchDocument}/access', [ChurchDocumentController::class, 'issueAccess']);
    Route::get('/{churchDocument}/download', [ChurchDocumentController::class, 'download']);
    Route::post('/{churchDocument}/archive-request', [ChurchDocumentController::class, 'requestArchive']);
    Route::delete('/{churchDocument}', [ChurchDocumentController::class, 'destroy']);
});

// Story 14.3: global permission-filtered church search
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('global-search')->group(function () {
    Route::get('/catalog', [GlobalSearchController::class, 'catalog']);
    Route::get('/', [GlobalSearchController::class, 'index']);
    Route::get('/resolve/{recordType}/{recordId}', [GlobalSearchController::class, 'resolve']);
    Route::get('/sync-failures', [GlobalSearchController::class, 'syncFailures']);
    Route::post('/process-retries', [GlobalSearchController::class, 'processRetries']);
});

// Story 15.1: profile, map, and validate legacy migration data
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('data-migrations')->group(function () {
    Route::get('/catalog', [DataMigrationController::class, 'catalog']);
    Route::get('/sources', [DataMigrationController::class, 'indexSources']);
    Route::post('/sources', [DataMigrationController::class, 'storeSource']);
    Route::post('/sources/{dataMigrationSource}/profile', [DataMigrationController::class, 'profileSource']);
    Route::post('/sources/{dataMigrationSource}/mappings', [DataMigrationController::class, 'storeMapping']);
    Route::put('/mappings/{dataMigrationMapping}/draft', [DataMigrationController::class, 'updateMappingDraft']);
    Route::post('/mappings/{dataMigrationMapping}/validate', [DataMigrationController::class, 'validateMapping']);
    Route::post('/mappings/{dataMigrationMapping}/test-sample', [DataMigrationController::class, 'testSample']);
    Route::post('/mappings/{dataMigrationMapping}/validate-run', [DataMigrationController::class, 'runValidation']);
    Route::get('/validation-runs/{dataMigrationValidationRun}', [DataMigrationController::class, 'showValidationRun']);
    Route::post('/mappings/{dataMigrationMapping}/approve', [DataMigrationController::class, 'approveMapping']);
    Route::post('/mappings/{dataMigrationMapping}/cutover-plans', [DataMigrationController::class, 'createCutoverPlan']);
    Route::get('/cutover-plans/{dataMigrationCutoverPlan}', [DataMigrationController::class, 'showCutoverPlan']);
    Route::post('/cutover-plans/{dataMigrationCutoverPlan}/test-run', [DataMigrationController::class, 'runTestMigration']);
    Route::post('/cutover-plans/{dataMigrationCutoverPlan}/uat-sign-off', [DataMigrationController::class, 'signOffUat']);
    Route::post('/cutover-plans/{dataMigrationCutoverPlan}/execute-production', [DataMigrationController::class, 'executeProduction']);
    Route::post('/cutover-plans/{dataMigrationCutoverPlan}/go-live', [DataMigrationController::class, 'approveGoLive']);
    Route::post('/cutover-plans/{dataMigrationCutoverPlan}/dispose', [DataMigrationController::class, 'disposeMigration']);
    Route::get('/runs/{dataMigrationRun}', [DataMigrationController::class, 'showRun']);
});

// Story 15.3: versioned REST APIs with documented contracts
Route::middleware(['auth.api_principal', 'mfa.enrolled', 'mfa.verified', 'api.platform'])->prefix('v1')->group(function () {
    Route::get('/members', [MemberController::class, 'index'])->name('api.v1.members.index');
    Route::get('/members/{member}', [MemberController::class, 'show'])->name('api.v1.members.show');
    Route::get('/organizations', [OrganizationController::class, 'index'])->name('api.v1.organizations.index');
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('platform')->group(function () {
    Route::get('/catalog', [ApiPlatformController::class, 'catalog']);
    Route::get('/contract', [ApiPlatformController::class, 'contract']);
    Route::get('/contract/validate', [ApiPlatformController::class, 'validateContract']);
    Route::get('/clients', [ApiPlatformController::class, 'indexClients']);
    Route::post('/clients', [ApiPlatformController::class, 'storeClient']);
    Route::post('/clients/{apiClient}/revoke', [ApiPlatformController::class, 'revokeClient']);
});

// Story 15.4: outbound webhook subscriptions and delivery
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('outbound-webhooks')->group(function () {
    Route::get('/catalog', [OutboundWebhookController::class, 'catalog']);
    Route::get('/subscriptions', [OutboundWebhookController::class, 'index']);
    Route::post('/subscriptions', [OutboundWebhookController::class, 'store']);
    Route::get('/subscriptions/{webhookSubscription}', [OutboundWebhookController::class, 'show']);
    Route::post('/subscriptions/{webhookSubscription}/verify', [OutboundWebhookController::class, 'verify']);
    Route::post('/subscriptions/{webhookSubscription}/revoke', [OutboundWebhookController::class, 'revoke']);
    Route::post('/subscriptions/{webhookSubscription}/rotate-secret', [OutboundWebhookController::class, 'rotateSecret']);
    Route::post('/dispatch', [OutboundWebhookController::class, 'dispatchEvent']);
    Route::post('/process-due', [OutboundWebhookController::class, 'processDue']);
});

// Story 15.5: external service adapters
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('external-adapters')->group(function () {
    Route::get('/catalog', [ExternalAdapterController::class, 'catalog']);
    Route::get('/', [ExternalAdapterController::class, 'index']);
    Route::post('/', [ExternalAdapterController::class, 'store']);
    Route::post('/invoke', [ExternalAdapterController::class, 'invoke']);
    Route::post('/process-due', [ExternalAdapterController::class, 'processDue']);
    Route::get('/{externalServiceAdapter}', [ExternalAdapterController::class, 'show']);
    Route::put('/{externalServiceAdapter}', [ExternalAdapterController::class, 'update']);
    Route::post('/{externalServiceAdapter}/test', [ExternalAdapterController::class, 'test']);
    Route::post('/{externalServiceAdapter}/activate', [ExternalAdapterController::class, 'activate']);
    Route::post('/{externalServiceAdapter}/disable', [ExternalAdapterController::class, 'disable']);
    Route::post('/{externalServiceAdapter}/replace', [ExternalAdapterController::class, 'replace']);
});

// Story 15.6: operations monitoring, backups, and recovery
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('operations-monitoring')->group(function () {
    Route::get('/catalog', [OperationsMonitoringController::class, 'catalog']);
    Route::get('/dashboard', [OperationsMonitoringController::class, 'dashboard']);
    Route::post('/collect-telemetry', [OperationsMonitoringController::class, 'collectTelemetry']);
    Route::post('/alerts/{operationsAlert}/acknowledge', [OperationsMonitoringController::class, 'acknowledgeAlert']);
    Route::post('/alerts/{operationsAlert}/resolve', [OperationsMonitoringController::class, 'resolveAlert']);
    Route::get('/backups', [OperationsMonitoringController::class, 'indexBackups']);
    Route::post('/backups', [OperationsMonitoringController::class, 'storeBackup']);
    Route::get('/recovery-exercises', [OperationsMonitoringController::class, 'indexRecoveryExercises']);
    Route::post('/recovery-exercises', [OperationsMonitoringController::class, 'storeRecoveryExercise']);
});

// Story 5.7: team report forms
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('team-report-forms')->group(function () {
    Route::get('/', [TeamReportFormController::class, 'index']);
    Route::post('/', [TeamReportFormController::class, 'store']);
    Route::get('/{teamReportForm}', [TeamReportFormController::class, 'show']);
    Route::put('/{teamReportForm}/draft', [TeamReportFormController::class, 'updateDraft']);
    Route::get('/{teamReportForm}/preview', [TeamReportFormController::class, 'preview']);
    Route::post('/{teamReportForm}/publish', [TeamReportFormController::class, 'publish']);
});

// Story 5.2: service team assignments
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('team-assignments')->group(function () {
    Route::post('/{assignment}/approve', [ServiceTeamAssignmentController::class, 'approve']);
    Route::post('/{assignment}/transfer', [ServiceTeamAssignmentController::class, 'transfer']);
    Route::post('/{assignment}/remove', [ServiceTeamAssignmentController::class, 'remove']);
});

// Story 6.1: church groups
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('groups')->group(function () {
    Route::get('/', [ChurchGroupController::class, 'index']);
    Route::post('/', [ChurchGroupController::class, 'store']);
    Route::get('/{churchGroup}', [ChurchGroupController::class, 'show']);
    Route::put('/{churchGroup}', [ChurchGroupController::class, 'update']);
    Route::post('/{churchGroup}/activate', [ChurchGroupController::class, 'activate']);
    Route::post('/{churchGroup}/members', [ChurchGroupController::class, 'assignMember']);
    Route::post('/{churchGroup}/members/{membership}/transfer', [ChurchGroupController::class, 'transferMember']);
    Route::post('/{churchGroup}/members/{membership}/remove', [ChurchGroupController::class, 'removeMember']);
    Route::post('/{churchGroup}/join-requests', [ChurchGroupController::class, 'submitJoinRequest']);
    Route::get('/{churchGroup}/meetings', [ChurchGroupMeetingController::class, 'index']);
    Route::post('/{churchGroup}/meetings', [ChurchGroupMeetingController::class, 'store']);
    Route::get('/{churchGroup}/meeting-dashboard', [ChurchGroupMeetingController::class, 'dashboard']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('group-meetings')->group(function () {
    Route::get('/{churchGroupMeeting}', [ChurchGroupMeetingController::class, 'show']);
    Route::post('/{churchGroupMeeting}/record', [ChurchGroupMeetingController::class, 'record']);
    Route::post('/{churchGroupMeeting}/evaluate-follow-ups', [ChurchGroupMeetingController::class, 'evaluateFollowUps']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('group-meeting-attendance')->group(function () {
    Route::post('/{churchGroupMeetingAttendance}/correct', [ChurchGroupMeetingController::class, 'correctAttendance']);
});

// Story 6.3: training and discipleship offerings
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('training-offerings')->group(function () {
    Route::get('/', [TrainingOfferingController::class, 'index']);
    Route::post('/', [TrainingOfferingController::class, 'store']);
    Route::get('/{trainingOffering}', [TrainingOfferingController::class, 'show']);
    Route::put('/{trainingOffering}', [TrainingOfferingController::class, 'update']);
    Route::post('/{trainingOffering}/publish', [TrainingOfferingController::class, 'publish']);
    Route::post('/{trainingOffering}/enrol', [TrainingOfferingController::class, 'enrol']);
    Route::get('/{trainingOffering}/enrolments', [TrainingOfferingController::class, 'enrolments']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('training-enrolments')->group(function () {
    Route::get('/{trainingEnrolment}', [TrainingOfferingController::class, 'showEnrolment']);
    Route::get('/{trainingEnrolment}/progress', [TrainingProgressController::class, 'show']);
    Route::post('/{trainingEnrolment}/attendance', [TrainingProgressController::class, 'recordAttendance']);
    Route::post('/{trainingEnrolment}/assessments', [TrainingProgressController::class, 'recordAssessments']);
    Route::post('/{trainingEnrolment}/confirm-completion', [TrainingProgressController::class, 'confirmCompletion']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('training-session-attendance')->group(function () {
    Route::post('/{trainingSessionAttendance}/correct', [TrainingProgressController::class, 'correctAttendance']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('training-assessment-results')->group(function () {
    Route::post('/{trainingAssessmentResult}/correct', [TrainingProgressController::class, 'correctAssessment']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('training-certificates')->group(function () {
    Route::post('/{trainingCertificate}/revoke', [TrainingProgressController::class, 'revokeCertificate']);
    Route::get('/verify/{reference}', [TrainingProgressController::class, 'verifyCertificate']);
});

// Story 7.1: welfare requests
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('welfare-requests')->group(function () {
    Route::get('/', [WelfareRequestController::class, 'index']);
    Route::post('/', [WelfareRequestController::class, 'store']);
    Route::get('/{welfareRequest}', [WelfareRequestController::class, 'show']);
    Route::put('/{welfareRequest}', [WelfareRequestController::class, 'update']);
    Route::post('/{welfareRequest}/submit', [WelfareRequestController::class, 'submit']);
    Route::post('/{welfareRequest}/assign', [WelfareRequestController::class, 'assign']);
    Route::post('/{welfareRequest}/assess', [WelfareRequestController::class, 'assess']);
    Route::post('/{welfareRequest}/conditions', [WelfareRequestController::class, 'recordCondition']);
    Route::post('/{welfareRequest}/approve', [WelfareRequestController::class, 'attemptApproval']);
    Route::post('/{welfareRequest}/decisions', [WelfareRequestController::class, 'decide']);
    Route::post('/{welfareRequest}/reevaluate-approvals', [WelfareRequestController::class, 'reevaluateApprovals']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('welfare-approval-configs')->group(function () {
    Route::get('/', [WelfareApprovalController::class, 'index']);
    Route::post('/publish', [WelfareApprovalController::class, 'publish']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('welfare-requests')->group(function () {
    Route::post('/{welfareRequest}/deliveries', [WelfareDeliveryController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('welfare-deliveries')->group(function () {
    Route::post('/{welfareAssistanceDelivery}/confirm', [WelfareDeliveryController::class, 'confirm']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('welfare-requests')->group(function () {
    Route::post('/{welfareRequest}/follow-ups', [WelfareFollowUpController::class, 'store']);
    Route::post('/{welfareRequest}/close', [WelfareFollowUpController::class, 'close']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('welfare-follow-ups')->group(function () {
    Route::post('/process-overdue', [WelfareFollowUpController::class, 'processOverdue']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('welfare-reports')->group(function () {
    Route::get('/', [WelfareFollowUpController::class, 'report']);
});

// Story 8.1–8.2: restricted pastoral care cases
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('care-cases')->group(function () {
    Route::get('/', [CareCaseController::class, 'index']);
    Route::post('/', [CareCaseController::class, 'store']);
    Route::post('/process-escalations', [CareCaseController::class, 'processEscalations']);
    Route::get('/{careCase}', [CareCaseController::class, 'show']);
    Route::post('/{careCase}/activities', [CareCaseController::class, 'recordActivity']);
    Route::post('/{careCase}/escalate', [CareCaseController::class, 'escalate']);
    Route::post('/{careCase}/close', [CareCaseController::class, 'close']);
    Route::post('/{careCase}/reopen', [CareCaseController::class, 'reopen']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('care-case-escalations')->group(function () {
    Route::post('/{careCaseEscalation}/acknowledge', [CareCaseController::class, 'acknowledgeEscalation']);
});

// Stories 8.3–8.4: prayer requests with confidentiality and processing
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('prayer-requests')->group(function () {
    Route::get('/', [PrayerRequestController::class, 'index']);
    Route::post('/', [PrayerRequestController::class, 'store']);
    Route::get('/{prayerRequest}', [PrayerRequestController::class, 'show']);
    Route::post('/{prayerRequest}/confidentiality', [PrayerRequestController::class, 'updateConfidentiality']);
    Route::post('/{prayerRequest}/withdraw', [PrayerRequestController::class, 'withdraw']);
    Route::post('/{prayerRequest}/assign', [PrayerRequestController::class, 'assign']);
    Route::post('/{prayerRequest}/acknowledge', [PrayerRequestController::class, 'acknowledge']);
    Route::post('/{prayerRequest}/updates', [PrayerRequestController::class, 'recordUpdate']);
    Route::post('/{prayerRequest}/escalate', [PrayerRequestController::class, 'escalate']);
    Route::post('/{prayerRequest}/answer', [PrayerRequestController::class, 'markAnswered']);
    Route::post('/{prayerRequest}/close', [PrayerRequestController::class, 'close']);
    Route::post('/{prayerRequest}/publish-to-group', [PrayerRequestController::class, 'publishToGroup']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('me/prayer-requests')->group(function () {
    Route::get('/', [PrayerRequestController::class, 'myRequests']);
    Route::post('/', [PrayerRequestController::class, 'store']);
});

// Story 9.1: operational tasks
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('tasks')->group(function () {
    Route::post('/process-overdue', [OperationalTaskController::class, 'processOverdue']);
    Route::get('/', [OperationalTaskController::class, 'index']);
    Route::post('/', [OperationalTaskController::class, 'store']);
    Route::get('/{operationalTask}', [OperationalTaskController::class, 'show']);
    Route::post('/{operationalTask}/status', [OperationalTaskController::class, 'changeStatus']);
    Route::post('/{operationalTask}/reassign', [OperationalTaskController::class, 'reassign']);
});

// Stories 9.2–9.3: reusable workflows and execution
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('workflows')->group(function () {
    Route::get('/', [WorkflowController::class, 'index']);
    Route::post('/', [WorkflowController::class, 'store']);
    Route::get('/{workflow}', [WorkflowController::class, 'show']);
    Route::put('/{workflow}/draft', [WorkflowController::class, 'updateDraft']);
    Route::get('/{workflow}/visualize', [WorkflowController::class, 'visualize']);
    Route::post('/{workflow}/validate', [WorkflowController::class, 'validateDefinition']);
    Route::post('/{workflow}/test', [WorkflowController::class, 'test']);
    Route::post('/{workflow}/publish', [WorkflowController::class, 'publish']);
    Route::post('/{workflow}/instances', [WorkflowInstanceController::class, 'start']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('workflow-instances')->group(function () {
    Route::post('/process-deadlines', [WorkflowInstanceController::class, 'processDeadlines']);
    Route::get('/', [WorkflowInstanceController::class, 'index']);
    Route::get('/{workflowInstance}', [WorkflowInstanceController::class, 'show']);
    Route::post('/{workflowInstance}/act', [WorkflowInstanceController::class, 'act']);
});

// Story 9.4: event-driven automation rules
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('automation-rules')->group(function () {
    Route::post('/evaluate', [AutomationRuleController::class, 'evaluate']);
    Route::post('/process-retries', [AutomationRuleController::class, 'processRetries']);
    Route::get('/', [AutomationRuleController::class, 'index']);
    Route::post('/', [AutomationRuleController::class, 'store']);
    Route::get('/{automationRule}', [AutomationRuleController::class, 'show']);
    Route::put('/{automationRule}/draft', [AutomationRuleController::class, 'updateDraft']);
    Route::post('/{automationRule}/validate', [AutomationRuleController::class, 'validateDefinition']);
    Route::post('/{automationRule}/simulate', [AutomationRuleController::class, 'simulate']);
    Route::post('/{automationRule}/publish', [AutomationRuleController::class, 'publish']);
    Route::post('/{automationRule}/enabled', [AutomationRuleController::class, 'setEnabled']);
});

// Story 10.1: permission-aware multi-channel communications
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('communications')->group(function () {
    Route::post('/process-due', [CommunicationController::class, 'processDue']);
    Route::post('/process-retries', [CommunicationController::class, 'processRetries']);
    Route::post('/suppressions', [CommunicationController::class, 'suppress']);
    Route::get('/', [CommunicationController::class, 'index']);
    Route::post('/', [CommunicationController::class, 'store']);
    Route::get('/{communication}', [CommunicationController::class, 'show']);
    Route::post('/{communication}/cancel', [CommunicationController::class, 'cancel']);
});

// Story 10.3: reusable message templates
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('message-templates')->group(function () {
    Route::get('/', [MessageTemplateController::class, 'index']);
    Route::post('/', [MessageTemplateController::class, 'store']);
    Route::get('/{messageTemplate}', [MessageTemplateController::class, 'show']);
    Route::put('/{messageTemplate}/draft', [MessageTemplateController::class, 'updateDraft']);
    Route::post('/{messageTemplate}/validate', [MessageTemplateController::class, 'validateDefinition']);
    Route::post('/{messageTemplate}/preview', [MessageTemplateController::class, 'preview']);
    Route::post('/{messageTemplate}/publish', [MessageTemplateController::class, 'publish']);
    Route::post('/{messageTemplate}/retire', [MessageTemplateController::class, 'retire']);
});

// Story 10.4: automated birthday and anniversary greetings
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('milestone-greetings')->group(function () {
    Route::get('/configs', [MilestoneGreetingController::class, 'indexConfigs']);
    Route::post('/configs', [MilestoneGreetingController::class, 'upsertConfig']);
    Route::get('/today', [MilestoneGreetingController::class, 'listToday']);
    Route::get('/evaluations', [MilestoneGreetingController::class, 'evaluations']);
    Route::post('/process', [MilestoneGreetingController::class, 'processWindow']);
    Route::post('/members/{member}/milestones', [MilestoneGreetingController::class, 'upsertMemberMilestone']);
});

// Story 10.5: newsletters
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('newsletters')->group(function () {
    Route::post('/process-due', [NewsletterController::class, 'processDue']);
    Route::get('/', [NewsletterController::class, 'index']);
    Route::post('/', [NewsletterController::class, 'store']);
    Route::get('/{newsletter}', [NewsletterController::class, 'show']);
    Route::put('/{newsletter}/draft', [NewsletterController::class, 'updateDraft']);
    Route::post('/{newsletter}/validate', [NewsletterController::class, 'validateDefinition']);
    Route::post('/{newsletter}/preview', [NewsletterController::class, 'preview']);
    Route::post('/{newsletter}/test-send', [NewsletterController::class, 'sendTest']);
    Route::post('/{newsletter}/submit', [NewsletterController::class, 'submit']);
    Route::post('/{newsletter}/approve', [NewsletterController::class, 'approve']);
    Route::post('/{newsletter}/events', [NewsletterController::class, 'recordEvent']);
    Route::get('/{newsletter}/analytics', [NewsletterController::class, 'analytics']);
});

// Story 10.6: moderated community spaces
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('community-spaces')->group(function () {
    Route::post('/purge-expired', [CommunitySpaceController::class, 'purgeExpired']);
    Route::get('/', [CommunitySpaceController::class, 'index']);
    Route::post('/', [CommunitySpaceController::class, 'store']);
    Route::get('/{communitySpace}', [CommunitySpaceController::class, 'show']);
    Route::post('/{communitySpace}/members', [CommunitySpaceController::class, 'addMember']);
    Route::get('/{communitySpace}/messages', [CommunitySpaceController::class, 'messages']);
    Route::post('/{communitySpace}/messages', [CommunitySpaceController::class, 'postMessage']);
    Route::get('/{communitySpace}/search', [CommunitySpaceController::class, 'search']);
    Route::post('/{communitySpace}/messages/{message}/pin', [CommunitySpaceController::class, 'pin']);
    Route::post('/{communitySpace}/messages/{message}/restrict', [CommunitySpaceController::class, 'restrict']);
    Route::post('/{communitySpace}/messages/{message}/remove', [CommunitySpaceController::class, 'remove']);
    Route::post('/{communitySpace}/messages/{message}/report', [CommunitySpaceController::class, 'report']);
    Route::post('/{communitySpace}/members/{user}/moderate', [CommunitySpaceController::class, 'moderateParticipant']);
    Route::post('/{communitySpace}/integrations', [CommunitySpaceController::class, 'configureIntegration']);
});

// Story 10.7: church content publishing
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('church-content')->group(function () {
    Route::post('/process-windows', [ChurchContentController::class, 'processWindows']);
    Route::get('/feed', [ChurchContentController::class, 'feed']);
    Route::get('/search', [ChurchContentController::class, 'search']);
    Route::get('/', [ChurchContentController::class, 'index']);
    Route::post('/', [ChurchContentController::class, 'store']);
    Route::get('/{churchContent}', [ChurchContentController::class, 'show']);
    Route::put('/{churchContent}/draft', [ChurchContentController::class, 'updateDraft']);
    Route::post('/{churchContent}/validate', [ChurchContentController::class, 'validateDefinition']);
    Route::post('/{churchContent}/preview', [ChurchContentController::class, 'preview']);
    Route::post('/{churchContent}/submit', [ChurchContentController::class, 'submit']);
    Route::post('/{churchContent}/approve', [ChurchContentController::class, 'approve']);
    Route::post('/{churchContent}/withdraw', [ChurchContentController::class, 'withdraw']);
});

// Story 11.1: payment sources (public webhook is signature-authenticated)
Route::post('/webhooks/payments/{provider}', [PaymentSourceController::class, 'webhook']);

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('payment-sources')->group(function () {
    Route::get('/contributions', [PaymentSourceController::class, 'contributions']);
    Route::get('/', [PaymentSourceController::class, 'index']);
    Route::post('/', [PaymentSourceController::class, 'store']);
    Route::get('/{paymentSource}', [PaymentSourceController::class, 'show']);
    Route::put('/{paymentSource}', [PaymentSourceController::class, 'update']);
    Route::post('/{paymentSource}/test', [PaymentSourceController::class, 'test']);
    Route::post('/{paymentSource}/ingest', [PaymentSourceController::class, 'ingest']);
});

// Story 11.2: contribution reconciliation and receipts
Route::get('/receipts/verify', [ContributionController::class, 'verifyReceipt']);

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('contributions')->group(function () {
    Route::get('/campaigns', [ContributionController::class, 'campaigns']);
    Route::post('/campaigns', [ContributionController::class, 'storeCampaign']);
    Route::get('/', [ContributionController::class, 'index']);
    Route::post('/manual', [ContributionController::class, 'storeManual']);
    Route::get('/{contribution}', [ContributionController::class, 'show']);
    Route::post('/{contribution}/match', [ContributionController::class, 'match']);
    Route::post('/{contribution}/needs-resolution', [ContributionController::class, 'needsResolution']);
    Route::post('/{contribution}/reconcile', [ContributionController::class, 'reconcile']);
    Route::post('/{contribution}/correct', [ContributionController::class, 'correct']);
    Route::post('/{contribution}/receipts', [ContributionController::class, 'issueReceipt']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('receipts')->group(function () {
    Route::post('/{receipt}/void', [ContributionController::class, 'voidReceipt']);
});

// Story 11.3: finance giving reports
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('giving')->group(function () {
    Route::get('/reports', [GivingReportController::class, 'report']);
    Route::get('/unauthorized', [GivingReportController::class, 'unauthorized']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('me/welfare-requests')->group(function () {
    Route::get('/', [WelfareRequestController::class, 'myRequests']);
    Route::post('/', [WelfareRequestController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('group-join-requests')->group(function () {
    Route::post('/{joinRequest}/review', [ChurchGroupController::class, 'reviewJoinRequest']);
});

// Story 5.3: volunteer profiles
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('volunteers')->group(function () {
    Route::get('/alerts', [VolunteerProfileController::class, 'alerts']);
    Route::get('/', [VolunteerProfileController::class, 'index']);
    Route::post('/', [VolunteerProfileController::class, 'store']);
    Route::get('/{volunteerProfile}', [VolunteerProfileController::class, 'show']);
    Route::put('/{volunteerProfile}', [VolunteerProfileController::class, 'update']);
    Route::post('/changes/{change}/verify', [VolunteerProfileController::class, 'verifyChange']);
});

// Story 4.6: operational incidents
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('incidents')->group(function () {
    Route::post('/process-escalations', [OperationalIncidentController::class, 'processEscalations']);
    Route::get('/', [OperationalIncidentController::class, 'index']);
    Route::post('/', [OperationalIncidentController::class, 'store']);
    Route::get('/{operationalIncident}', [OperationalIncidentController::class, 'show']);
    Route::post('/{operationalIncident}/activities', [OperationalIncidentController::class, 'recordActivity']);
    Route::post('/{operationalIncident}/review', [OperationalIncidentController::class, 'review']);
});

// Story 4.5: gathering feedback
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('feedback')->group(function () {
    Route::get('/', [GatheringFeedbackController::class, 'index']);
    Route::get('/{gatheringFeedback}', [GatheringFeedbackController::class, 'show']);
    Route::post('/{gatheringFeedback}/activities', [GatheringFeedbackController::class, 'recordActivity']);
});

// Story 3.3: attendance exceptions
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('attendance')->group(function () {
    Route::get('/records', [AttendanceRecordController::class, 'index']);
    Route::post('/records', [AttendanceRecordController::class, 'store']);
    Route::post('/records/{record}/correct', [AttendanceRecordController::class, 'correct']);
    Route::post('/capture', [AttendanceRecordController::class, 'capture']);
    Route::post('/sync', [AttendanceRecordController::class, 'sync']);
    Route::get('/sessions/{sessionKey}/{sessionId}/records', [AttendanceRecordController::class, 'sessionRecords']);
    Route::get('/exceptions', [AttendanceExceptionController::class, 'index']);
    Route::get('/exceptions/{exception}', [AttendanceExceptionController::class, 'show']);
    Route::get('/rules', [AttendanceExceptionController::class, 'rules']);
    Route::post('/rules', [AttendanceExceptionController::class, 'storeRule']);
    Route::post('/rules/{rule}/publish', [AttendanceExceptionController::class, 'publishRule']);
});

// Story 3.2: onboarding journeys
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('onboarding')->group(function () {
    Route::post('/process-due', [OnboardingJourneyController::class, 'processDue']);
    Route::get('/enrollments', [OnboardingJourneyController::class, 'enrollments']);
    Route::get('/enrollments/{enrollment}', [OnboardingJourneyController::class, 'showEnrollment']);
    Route::get('/journeys', [OnboardingJourneyController::class, 'index']);
    Route::post('/journeys', [OnboardingJourneyController::class, 'store']);
    Route::put('/journeys/{journey}', [OnboardingJourneyController::class, 'update']);
    Route::post('/journeys/{journey}/publish', [OnboardingJourneyController::class, 'publish']);
});

// Story 3.1: visitor capture
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('visitors')->group(function () {
    Route::get('/export', [VisitorController::class, 'export']);
    Route::get('/', [VisitorController::class, 'index']);
    Route::post('/', [VisitorController::class, 'store']);
    Route::get('/{visitor}', [VisitorController::class, 'show']);
    Route::post('/{visitor}/visits', [VisitorController::class, 'recordVisit']);
});

// Story 2.3: households — literal member routes before /{household}
Route::middleware(['auth:sanctum', 'mfa.enrolled', 'mfa.verified'])->prefix('households')->group(function () {
    Route::get('/', [HouseholdController::class, 'index']);
    Route::post('/', [HouseholdController::class, 'store']);
    Route::get('/{household}', [HouseholdController::class, 'show']);
    Route::put('/{household}', [HouseholdController::class, 'update']);
    Route::post('/{household}/members', [HouseholdController::class, 'addMember']);
    Route::post('/{household}/members/{member}/relationship', [HouseholdController::class, 'changeRelationship']);
    Route::post('/{household}/members/{member}/remove', [HouseholdController::class, 'removeMember']);
});