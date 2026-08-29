import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

// Lazy load components
const Login = () => import('../components/LoginForm.vue');
const Dashboard = () => import('../pages/Dashboard.vue');
const OrganizationManagement = () => import('../pages/OrganizationManagement.vue');
const MemberMovements = () => import('../pages/MemberMovements.vue');
const RoleManagement = () => import('../pages/RoleManagement.vue');
const ConfigurationManagement = () => import('../pages/ConfigurationManagement.vue');
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