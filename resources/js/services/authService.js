import api from '../api/client';

export const authService = {
  /**
   * Login a user
   * @param {Object} credentials - User login credentials
   * @returns {Promise<Object>} Authentication response
   */
  async login(credentials) {
    try {
      // Make sure we're calling the correct API endpoint
      const response = await api.post('/auth/login', credentials);
      return response.data;
    } catch (error) {
      throw error.response?.data || error;
    }
  },

  /**
   * Logout current user
   * @returns {Promise<Object>} Logout response
   */
  async logout() {
    try {
      const response = await api.post('/auth/logout');
      return response.data;
    } catch (error) {
      throw error.response?.data || error;
    }
  },

  /**
   * Get current authenticated user
   * @returns {Promise<Object>} User data
   */
  async getUser() {
    try {
      const response = await api.get('/auth/user');
      return response.data;
    } catch (error) {
      throw error.response?.data || error;
    }
  },

  /**
   * Check if user is authenticated
   * @returns {boolean} Whether user is authenticated
   */
  isAuthenticated() {
    return !!localStorage.getItem('access_token');
  },

  /**
   * Get access token from localStorage
   * @returns {string|null} Access token
   */
  getAccessToken() {
    return localStorage.getItem('access_token');
  },

  /**
   * Set access token in localStorage
   * @param {string} token - Access token
   */
  setAccessToken(token) {
    localStorage.setItem('access_token', token);
  },

  /**
   * Remove access token from localStorage
   */
  removeAccessToken() {
    localStorage.removeItem('access_token');
  }
};