import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

// Lazy load components
const Login = () => import('../components/LoginForm.vue');
const Dashboard = () => import('../pages/Dashboard.vue');
const OrganizationManagement = () => import('../pages/OrganizationManagement.vue');
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

];

const router = createRouter({
  history: createWebHistory(),
  routes
});

// Navigation guards - using modern Vue Router syntax
router.beforeEach((to, from, next) => {
  const authStore = useAuthStore();
  
  // Check if route requires authentication
  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    // Redirect to login if not authenticated
    next('/login');
  } else if (to.meta.requiresGuest && authStore.isAuthenticated) {
    // Redirect to dashboard if already authenticated
    next('/dashboard');
  } else {
    // Continue with navigation
    next();
  }
});

export default router;