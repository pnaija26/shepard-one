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
        this.user = null;
        this.isAuthenticated = false;
        authService.removeAccessToken();
      } catch (error) {
        console.error('Logout error:', error);
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
        // If we can't get user, log out the user
        this.logout();
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