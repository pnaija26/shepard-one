import axios from 'axios';
import { apiBaseUrl, apiVersion, apiVersionHeaderName, assertHttpsBaseUrl } from '../mobile/apiConfig';
import { credentialStore } from '../mobile/secureStorage';

assertHttpsBaseUrl(apiBaseUrl());

const api = axios.create({
  baseURL: apiBaseUrl(),
  timeout: 10000,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    [apiVersionHeaderName()]: apiVersion(),
  },
});

api.interceptors.request.use(
  async (config) => {
    const token = credentialStore.getAccessToken() || localStorage.getItem('access_token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    config.headers[apiVersionHeaderName()] = apiVersion();
    return config;
  },
  (error) => Promise.reject(error),
);

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error.response?.status === 401) {
      await credentialStore.clear();
      localStorage.removeItem('access_token');
      if (typeof window !== 'undefined' && !window.location.pathname.startsWith('/login')) {
        window.location.href = '/login';
      }
    }
    return Promise.reject(error);
  },
);

export function extractApiError(error, fallback = 'Something went wrong') {
  const data = error?.response?.data;

  if (data && typeof data === 'object' && data.errors && typeof data.errors === 'object' && !Array.isArray(data.errors)) {
    const firstField = Object.values(data.errors)[0];
    if (Array.isArray(firstField) && firstField[0]) return firstField[0];
  }

  if (data && typeof data === 'object' && Array.isArray(data.errors)) {
    const firstField = Object.values(data.errors)[0];
    if (firstField?.[0]) return firstField[0];
  }

  if (typeof data?.message === 'string' && data.message) return data.message;
  if (error?.message && error.message !== 'Request failed with status code') {
    return error.message.replace(/\s*\(\d{3}\)$/, '');
  }

  return fallback;
}

export default api;
