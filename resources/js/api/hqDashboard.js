import api from './client'

export function fetchHqDashboard(params = {}) {
  const query = new URLSearchParams(params).toString()

  return api.get(`/me/hq-dashboard${query ? `?${query}` : ''}`)
}

export function fetchHqDashboardDrillDown(metric, params = {}) {
  const query = new URLSearchParams(params).toString()

  return api.get(`/me/hq-dashboard/drill-down/${metric}${query ? `?${query}` : ''}`)
}
