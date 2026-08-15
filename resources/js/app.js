import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import { useAuthStore } from './stores/auth';

// Create the app instance
const app = createApp(App);

// Install plugins
const pinia = createPinia();
app.use(pinia);

const bootstrap = async () => {
	const authStore = useAuthStore(pinia);
	const authenticatedUser = window.__AUTH_USER__;

	if (authenticatedUser) {
		authStore.user = authenticatedUser;
		authStore.isAuthenticated = true;
	} else {
		await authStore.fetchUser();
	}

	app.use(router);

	// Mount the app
	app.mount('#app');
};

bootstrap();