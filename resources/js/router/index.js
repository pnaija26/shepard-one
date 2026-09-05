import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

// Lazy load components
const Login = () => import('../components/LoginForm.vue');
const Dashboard = () => import('../pages/Dashboard.vue');
const OrganizationManagement = () => import('../pages/OrganizationManagement.vue');
const MemberMovements = () => import('../pages/MemberMovements.vue');
const RoleManagement = () => import('../pages/RoleManagement.vue');
const ConfigurationManagement = () => import('../pages/ConfigurationManagement.vue');
const AuditLog = () => import('../pages/AuditLog.vue');
const Members = () => import('../pages/Members.vue');
const MyProfile = () => import('../pages/MyProfile.vue');
const MembershipCard = () => import('../pages/MembershipCard.vue');
const MembershipCardScan = () => import('../pages/MembershipCardScan.vue');
const Directory = () => import('../pages/Directory.vue');
const DirectoryPrivacy = () => import('../pages/DirectoryPrivacy.vue');
const Visitors = () => import('../pages/Visitors.vue');
const OnboardingJourneys = () => import('../pages/OnboardingJourneys.vue');
const AttendanceExceptions = () => import('../pages/AttendanceExceptions.vue');
const AttendanceCapture = () => import('../pages/AttendanceCapture.vue');
const GatheringFeedback = () => import('../pages/GatheringFeedback.vue');
const OperationalIncidents = () => import('../pages/OperationalIncidents.vue');
const ServiceTeams = () => import('../pages/ServiceTeams.vue');
const TeamRosters = () => import('../pages/TeamRosters.vue');
const TeamAttendance = () => import('../pages/TeamAttendance.vue');
const TeamReports = () => import('../pages/TeamReports.vue');
const TeamReportForms = () => import('../pages/TeamReportForms.vue');
const ChurchGroups = () => import('../pages/ChurchGroups.vue');
const TrainingOfferings = () => import('../pages/TrainingOfferings.vue');
const WelfareRequests = () => import('../pages/WelfareRequests.vue');
const CareCases = () => import('../pages/CareCases.vue');
const PrayerRequests = () => import('../pages/PrayerRequests.vue');
const OperationalTasks = () => import('../pages/OperationalTasks.vue');
const Workflows = () => import('../pages/Workflows.vue');
const AutomationRules = () => import('../pages/AutomationRules.vue');
const Communications = () => import('../pages/Communications.vue');
const NotificationInbox = () => import('../pages/NotificationInbox.vue');
const MessageTemplates = () => import('../pages/MessageTemplates.vue');
const MilestoneGreetings = () => import('../pages/MilestoneGreetings.vue');
const Newsletters = () => import('../pages/Newsletters.vue');
const CommunitySpaces = () => import('../pages/CommunitySpaces.vue');
const ChurchContent = () => import('../pages/ChurchContent.vue');
const PaymentSources = () => import('../pages/PaymentSources.vue');
const Contributions = () => import('../pages/Contributions.vue');
const MyGiving = () => import('../pages/MyGiving.vue');
const GivingReports = () => import('../pages/GivingReports.vue');
const HybridFoundation = () => import('../pages/HybridFoundation.vue');
const MemberHome = () => import('../pages/MemberHome.vue');
const TeamDashboard = () => import('../pages/TeamDashboard.vue');
const BranchDashboard = () => import('../pages/BranchDashboard.vue');
const HqDashboard = () => import('../pages/HqDashboard.vue');
const ComposableDashboards = () => import('../pages/ComposableDashboards.vue');
const MyComposableDashboard = () => import('../pages/MyComposableDashboard.vue');
const StandardReports = () => import('../pages/StandardReports.vue');
const CustomReports = () => import('../pages/CustomReports.vue');
const ReportSchedules = () => import('../pages/ReportSchedules.vue');
const ChurchDocuments = () => import('../pages/ChurchDocuments.vue');
const GlobalSearch = () => import('../pages/GlobalSearch.vue');
const DataMigrations = () => import('../pages/DataMigrations.vue');
const ApiPlatform = () => import('../pages/ApiPlatform.vue');
const OutboundWebhooks = () => import('../pages/OutboundWebhooks.vue');
const ExternalAdapters = () => import('../pages/ExternalAdapters.vue');
const OperationsMonitoring = () => import('../pages/OperationsMonitoring.vue');
const MyRosterAssignments = () => import('../pages/MyRosterAssignments.vue');
const Volunteers = () => import('../pages/Volunteers.vue');
const MyVolunteerProfile = () => import('../pages/MyVolunteerProfile.vue');
const FollowUps = () => import('../pages/FollowUps.vue');
const ChurchServices = () => import('../pages/ChurchServices.vue');
const ChurchEvents = () => import('../pages/ChurchEvents.vue');
const EventAdmissionScan = () => import('../pages/EventAdmissionScan.vue');
const Households = () => import('../pages/Households.vue');
const TestPage = () => import('../pages/TestPage.vue');

const routes = [
  {
    path: '/',
    redirect: '/login'
  },
  {
    path: '/login',
    name: 'Login',
    component: Login,
    meta: { requiresGuest: true }
  },
  {
    path: '/dashboard',
    name: 'Dashboard',
    component: Dashboard,
    meta: { requiresAuth: true }
  },
  {
    path: '/organizations',
    name: 'OrganizationManagement',
    component: OrganizationManagement,
    meta: { requiresAuth: true }
  },
  {
    path: '/movements',
    name: 'MemberMovements',
    component: MemberMovements,
    meta: { requiresAuth: true }
  },
  {
    path: '/roles',
    name: 'RoleManagement',
    component: RoleManagement,
    meta: { requiresAuth: true }
  },
  {
    path: '/config',
    name: 'ConfigurationManagement',
    component: ConfigurationManagement,
    meta: { requiresAuth: true }
  },
  {
    path: '/audit',
    name: 'AuditLog',
    component: AuditLog,
    meta: { requiresAuth: true }
  },
  {
    path: '/members',
    name: 'Members',
    component: Members,
    meta: { requiresAuth: true }
  },
  {
    path: '/my-profile',
    name: 'MyProfile',
    component: MyProfile,
    meta: { requiresAuth: true }
  },
  {
    path: '/my-membership-card',
    name: 'MembershipCard',
    component: MembershipCard,
    meta: { requiresAuth: true }
  },
  {
    path: '/membership-card/scan',
    name: 'MembershipCardScan',
    component: MembershipCardScan,
    meta: { requiresAuth: true }
  },
  {
    path: '/directory',
    name: 'Directory',
    component: Directory,
    meta: { requiresAuth: true }
  },
  {
    path: '/directory-privacy',
    name: 'DirectoryPrivacy',
    component: DirectoryPrivacy,
    meta: { requiresAuth: true }
  },
  {
    path: '/visitors',
    name: 'Visitors',
    component: Visitors,
    meta: { requiresAuth: true }
  },
  {
    path: '/onboarding',
    name: 'OnboardingJourneys',
    component: OnboardingJourneys,
    meta: { requiresAuth: true }
  },
  {
    path: '/attendance-exceptions',
    name: 'AttendanceExceptions',
    component: AttendanceExceptions,
    meta: { requiresAuth: true }
  },
  {
    path: '/attendance-capture',
    name: 'AttendanceCapture',
    component: AttendanceCapture,
    meta: { requiresAuth: true }
  },
  {
    path: '/feedback',
    name: 'GatheringFeedback',
    component: GatheringFeedback,
    meta: { requiresAuth: true }
  },
  {
    path: '/incidents',
    name: 'OperationalIncidents',
    component: OperationalIncidents,
    meta: { requiresAuth: true }
  },
  {
    path: '/service-teams',
    name: 'ServiceTeams',
    component: ServiceTeams,
    meta: { requiresAuth: true }
  },
  {
    path: '/team-rosters',
    name: 'TeamRosters',
    component: TeamRosters,
    meta: { requiresAuth: true }
  },
  {
    path: '/team-attendance',
    name: 'TeamAttendance',
    component: TeamAttendance,
    meta: { requiresAuth: true }
  },
  {
    path: '/team-reports',
    name: 'TeamReports',
    component: TeamReports,
    meta: { requiresAuth: true }
  },
  {
    path: '/team-report-forms',
    name: 'TeamReportForms',
    component: TeamReportForms,
    meta: { requiresAuth: true }
  },
  {
    path: '/groups',
    name: 'ChurchGroups',
    component: ChurchGroups,
    meta: { requiresAuth: true }
  },
  {
    path: '/training',
    name: 'TrainingOfferings',
    component: TrainingOfferings,
    meta: { requiresAuth: true }
  },
  {
    path: '/welfare',
    name: 'WelfareRequests',
    component: WelfareRequests,
    meta: { requiresAuth: true }
  },
  {
    path: '/care',
    name: 'CareCases',
    component: CareCases,
    meta: { requiresAuth: true }
  },
  {
    path: '/prayer',
    name: 'PrayerRequests',
    component: PrayerRequests,
    meta: { requiresAuth: true }
  },
  {
    path: '/tasks',
    name: 'OperationalTasks',
    component: OperationalTasks,
    meta: { requiresAuth: true }
  },
  {
    path: '/workflows',
    name: 'Workflows',
    component: Workflows,
    meta: { requiresAuth: true }
  },
  {
    path: '/automation-rules',
    name: 'AutomationRules',
    component: AutomationRules,
    meta: { requiresAuth: true }
  },
  {
    path: '/communications',
    name: 'Communications',
    component: Communications,
    meta: { requiresAuth: true }
  },
  {
    path: '/notifications',
    name: 'NotificationInbox',
    component: NotificationInbox,
    meta: { requiresAuth: true }
  },
  {
    path: '/message-templates',
    name: 'MessageTemplates',
    component: MessageTemplates,
    meta: { requiresAuth: true }
  },
  {
    path: '/milestone-greetings',
    name: 'MilestoneGreetings',
    component: MilestoneGreetings,
    meta: { requiresAuth: true }
  },
  {
    path: '/newsletters',
    name: 'Newsletters',
    component: Newsletters,
    meta: { requiresAuth: true }
  },
  {
    path: '/community-spaces',
    name: 'CommunitySpaces',
    component: CommunitySpaces,
    meta: { requiresAuth: true }
  },
  {
    path: '/church-content',
    name: 'ChurchContent',
    component: ChurchContent,
    meta: { requiresAuth: true }
  },
  {
    path: '/payment-sources',
    name: 'PaymentSources',
    component: PaymentSources,
    meta: { requiresAuth: true }
  },
  {
    path: '/contributions',
    name: 'Contributions',
    component: Contributions,
    meta: { requiresAuth: true }
  },
  {
    path: '/my-giving',
    name: 'MyGiving',
    component: MyGiving,
    meta: { requiresAuth: true }
  },
  {
    path: '/giving-reports',
    name: 'GivingReports',
    component: GivingReports,
    meta: { requiresAuth: true }
  },
  {
    path: '/team-dashboard',
    name: 'TeamDashboard',
    component: TeamDashboard,
    meta: { requiresAuth: true }
  },
  {
    path: '/branch-dashboard',
    name: 'BranchDashboard',
    component: BranchDashboard,
    meta: { requiresAuth: true }
  },
  {
    path: '/hq-dashboard',
    name: 'HqDashboard',
    component: HqDashboard,
    meta: { requiresAuth: true }
  },
  {
    path: '/composable-dashboards',
    name: 'ComposableDashboards',
    component: ComposableDashboards,
    meta: { requiresAuth: true }
  },
  {
    path: '/my-composable-dashboard',
    name: 'MyComposableDashboard',
    component: MyComposableDashboard,
    meta: { requiresAuth: true }
  },
  {
    path: '/standard-reports',
    name: 'StandardReports',
    component: StandardReports,
    meta: { requiresAuth: true }
  },
  {
    path: '/custom-reports',
    name: 'CustomReports',
    component: CustomReports,
    meta: { requiresAuth: true }
  },
  {
    path: '/report-schedules',
    name: 'ReportSchedules',
    component: ReportSchedules,
    meta: { requiresAuth: true }
  },
  {
    path: '/church-documents',
    name: 'ChurchDocuments',
    component: ChurchDocuments,
    meta: { requiresAuth: true }
  },
  {
    path: '/global-search',
    name: 'GlobalSearch',
    component: GlobalSearch,
    meta: { requiresAuth: true }
  },
  {
    path: '/data-migrations',
    name: 'DataMigrations',
    component: DataMigrations,
    meta: { requiresAuth: true }
  },
  {
    path: '/api-platform',
    name: 'ApiPlatform',
    component: ApiPlatform,
    meta: { requiresAuth: true }
  },
  {
    path: '/outbound-webhooks',
    name: 'OutboundWebhooks',
    component: OutboundWebhooks,
    meta: { requiresAuth: true }
  },
  {
    path: '/external-adapters',
    name: 'ExternalAdapters',
    component: ExternalAdapters,
    meta: { requiresAuth: true }
  },
  {
    path: '/operations-monitoring',
    name: 'OperationsMonitoring',
    component: OperationsMonitoring,
    meta: { requiresAuth: true }
  },
  {
    path: '/my-roster-assignments',
    name: 'MyRosterAssignments',
    component: MyRosterAssignments,
    meta: { requiresAuth: true }
  },
  {
    path: '/volunteers',
    name: 'Volunteers',
    component: Volunteers,
    meta: { requiresAuth: true }
  },
  {
    path: '/my-volunteer-profile',
    name: 'MyVolunteerProfile',
    component: MyVolunteerProfile,
    meta: { requiresAuth: true }
  },
  {
    path: '/follow-ups',
    name: 'FollowUps',
    component: FollowUps,
    meta: { requiresAuth: true }
  },
  {
    path: '/services',
    name: 'ChurchServices',
    component: ChurchServices,
    meta: { requiresAuth: true }
  },
  {
    path: '/events',
    name: 'ChurchEvents',
    component: ChurchEvents,
    meta: { requiresAuth: true }
  },
  {
    path: '/events/admission-scan',
    name: 'EventAdmissionScan',
    component: EventAdmissionScan,
    meta: { requiresAuth: true }
  },
  {
    path: '/households',
    name: 'Households',
    component: Households,
    meta: { requiresAuth: true }
  },
  {
    path: '/home',
    name: 'MemberHome',
    component: MemberHome,
    meta: { requiresAuth: true }
  },
  {
    path: '/hybrid',
    name: 'HybridFoundation',
    component: HybridFoundation,
    meta: { requiresAuth: true }
  },

];

const router = createRouter({
  history: createWebHistory(),
  routes
});

// Navigation guards - using modern Vue Router syntax (return-based, no next())
router.beforeEach((to) => {
  const authStore = useAuthStore();

  // Check if route requires authentication
  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    // Redirect to login if not authenticated
    return '/login';
  } else if (to.meta.requiresGuest && authStore.isAuthenticated) {
    // Redirect to dashboard if already authenticated
    return '/dashboard';
  }

  // Continue with navigation
  return true;
});

export default router;