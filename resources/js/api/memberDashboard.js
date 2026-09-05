import api from './client';

export function fetchDashboard(params = {}) {
  return api.get('/me/dashboard', { params });
}
