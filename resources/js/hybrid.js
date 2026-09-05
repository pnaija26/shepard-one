import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import { useAuthStore } from './stores/auth';
import { initHybridShell } from './mobile/shell';
import { credentialStore } from './mobile/secureStorage';
import '../css/app.css';

/**
 * Story 12.1: Capacitor entry — same Vue app, hybrid platform bootstrap.
 */
const bootstrap = async () => {
  await initHybridShell();
  await credentialStore.hydrate();

  const app = createApp(App);
  const pinia = createPinia();
  app.use(pinia);

  const authStore = useAuthStore(pinia);
  authStore.checkAuthStatus();
  if (authStore.isAuthenticated) {
    await authStore.fetchUser();
  }

  app.use(router);
  app.mount('#app');
};

bootstrap();
