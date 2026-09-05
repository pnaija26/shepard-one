import api from '../api/client';
import { credentialStore } from '../mobile/secureStorage';
import { getPlatform, hybridClientLabel, isNativePlatform } from '../mobile/platform';

export const authService = {
  async login(credentials) {
    try {
      const deviceId = await credentialStore.ensureDeviceId();
      const payload = {
        ...credentials,
        client: hybridClientLabel() === 'hybrid' || isNativePlatform() ? 'hybrid' : (credentials.client || 'web'),
        device_id: deviceId,
        device_name: credentials.device_name || `${getPlatform()} ShepardOne`,
        platform: isNativePlatform() ? getPlatform() : (credentials.platform || 'web-hybrid'),
      };

      // Only send hybrid device fields when explicitly hybrid.
      if (payload.client !== 'hybrid') {
        delete payload.device_id;
        delete payload.device_name;
        delete payload.platform;
      }

      const response = await api.post('/auth/login', payload);
      return response.data;
    } catch (error) {
      throw error.response?.data || error;
    }
  },

  async logout() {
    try {
      const response = await api.post('/auth/logout');
      return response.data;
    } catch (error) {
      throw error.response?.data || error;
    }
  },

  async refreshDevice() {
    const refreshToken = credentialStore.getRefreshToken();
    const deviceId = credentialStore.getDeviceId();
    if (!refreshToken || !deviceId) {
      throw new Error('No device credential available to refresh.');
    }

    const response = await api.post('/auth/device/refresh', {
      refresh_token: refreshToken,
      device_id: deviceId,
    });
    return response.data;
  },

  async revokeDevice(deviceId = null) {
    const response = await api.post('/auth/device/revoke', {
      device_id: deviceId || credentialStore.getDeviceId(),
    });
    return response.data;
  },

  async getUser() {
    try {
      const response = await api.get('/auth/user');
      return response.data;
    } catch (error) {
      throw error.response?.data || error;
    }
  },

  isAuthenticated() {
    return !!credentialStore.getAccessToken() || !!localStorage.getItem('access_token');
  },

  getAccessToken() {
    return credentialStore.getAccessToken() || localStorage.getItem('access_token');
  },

  async setAccessToken(token, refreshToken = null) {
    await credentialStore.setTokens({ accessToken: token, refreshToken });
    if (token) {
      localStorage.setItem('access_token', token);
    } else {
      localStorage.removeItem('access_token');
    }
  },

  async removeAccessToken() {
    await credentialStore.clear();
    localStorage.removeItem('access_token');
  },
};
