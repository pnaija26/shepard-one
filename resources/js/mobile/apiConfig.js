/**
 * Story 12.1: versioned HTTPS API configuration for hybrid clients.
 */
const DEFAULT_VERSION = '1';

export function apiBaseUrl() {
  const configured = import.meta.env.VITE_API_BASE_URL;
  if (configured && String(configured).trim() !== '') {
    return String(configured).replace(/\/$/, '');
  }
  return '/api';
}

export function apiVersion() {
  return String(import.meta.env.VITE_API_VERSION || DEFAULT_VERSION);
}

export function apiVersionHeaderName() {
  return 'X-API-Version';
}

export function assertHttpsBaseUrl(url) {
  if (!url || url.startsWith('/')) {
    return true;
  }
  const requireHttps = import.meta.env.VITE_HYBRID_REQUIRE_HTTPS !== 'false';
  if (!requireHttps) {
    return true;
  }
  if (url.startsWith('https://')) {
    return true;
  }
  if (url.startsWith('http://localhost') || url.startsWith('http://127.0.0.1')) {
    return true;
  }
  throw new Error('Hybrid API base URL must use HTTPS outside local development.');
}
