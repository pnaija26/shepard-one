import axios from 'axios';

// Create axios instance with default configuration
const api = axios.create({
  baseURL: '/api',
  timeout: 10000,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  }
});

// Request interceptor to add auth token
api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('access_token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

// Response interceptor to handle errors
api.interceptors.response.use(
  (response) => {
    return response;
  },
  (error) => {
    if (error.response?.status === 401) {
      // Handle unauthorized access - redirect to login
      localStorage.removeItem('access_token');
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);

// Extract a human-readable message from an axios/Laravel validation error.
export function extractApiError(error, fallback = 'Something went wrong') {
  const data = error?.response?.data;

  // Laravel validation errors: first field's first message is the most useful.
  if (data && typeof data === 'object' && Array.isArray(data.errors)) {
    const firstField = Object.values(data.errors)[0];
    if (firstField?.[0]) return firstField[0];
  }

  if (typeof data?.message === 'string' && data.message) return data.message;
  if (error?.message && error.message !== 'Request failed with status code') {
    // Strip the trailing " (500)" style suffix axios appends.
    return error.message.replace(/\s*\(\d{3}\)$/, '');
  }

  return fallback;
}

export default api;