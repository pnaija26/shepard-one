import { defineStore } from 'pinia';
import { authService } from '../services/authService';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    isAuthenticated: false,
    loading: false,
    error: null
  }),

  actions: {
    /**
     * Login a user
     * @param {Object} credentials - User login credentials
     */
    async login(credentials) {
      this.loading = true;
      this.error = null;
      
      try {
        const response = await authService.login(credentials);

        if (!response.access_token) {
          throw new Error(response.message || 'Authentication did not return an access token');
        }
        
        // Store the access token
        authService.setAccessToken(response.access_token);
        
        // Set user data
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

    /**
     * Logout current user
     */
    async logout() {
      try {
        await authService.logout();
      } catch (error) {
        console.error('Logout error:', error);
      } finally {
        this.user = null;
        this.isAuthenticated = false;
        authService.removeAccessToken();
      }
    },

    /**
     * Fetch current user data
     */
    async fetchUser() {
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
        authService.removeAccessToken();
      } finally {
        this.loading = false;
      }
    },

    /**
     * Check authentication status
     */
    checkAuthStatus() {
      if (authService.isAuthenticated()) {
        this.isAuthenticated = true;
      } else {
        this.isAuthenticated = false;
        this.user = null;
      }
    }
  },

  // Persist store to localStorage
  persist: {
    storage: localStorage,
    paths: ['user', 'isAuthenticated']
  }
});