import { defineStore } from 'pinia';
import { authService } from '../services/authService';
import { credentialStore } from '../mobile/secureStorage';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    isAuthenticated: false,
    loading: false,
    error: null,
  }),

  actions: {
    async login(credentials) {
      this.loading = true;
      this.error = null;

      try {
        await credentialStore.hydrate();
        const response = await authService.login(credentials);

        if (!response.access_token) {
          throw new Error(response.message || 'Authentication did not return an access token');
        }

        await authService.setAccessToken(response.access_token, response.refresh_token ?? null);

        this.user = response.user;
        this.isAuthenticated = true;

        return response;
      } catch (error) {
        this.error = error.message || 'Login failed';
        throw error;
      } finally {
        this.loading = false;
      }
    },

    async logout() {
      try {
        await authService.logout();
      } catch (error) {
        console.error('Logout error:', error);
      } finally {
        this.user = null;
        this.isAuthenticated = false;
        await authService.removeAccessToken();
      }
    },

    async refreshSession() {
      const response = await authService.refreshDevice();
      await authService.setAccessToken(response.access_token, response.refresh_token);
      if (response.user) {
        this.user = response.user;
      }
      this.isAuthenticated = true;
      return response;
    },

    async fetchUser() {
      await credentialStore.hydrate();
      if (!authService.isAuthenticated()) {
        return;
      }

      this.loading = true;

      try {
        const user = await authService.getUser();
        this.user = user;
        this.isAuthenticated = true;
      } catch (error) {
        console.error('Failed to fetch user:', error);
        this.error = error.message || 'Failed to fetch user';
        this.user = null;
        this.isAuthenticated = false;
        await authService.removeAccessToken();
      } finally {
        this.loading = false;
      }
    },

    checkAuthStatus() {
      if (authService.isAuthenticated()) {
        this.isAuthenticated = true;
      } else {
        this.isAuthenticated = false;
        this.user = null;
      }
    },
  },
});
