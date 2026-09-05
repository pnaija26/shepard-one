import api from './client'

export function fetchDashboardCatalog() {
  return api.get('/composable-dashboards/catalog')
}

export function listComposableDashboards() {
  return api.get('/composable-dashboards')
}

export function createComposableDashboard(payload) {
  return api.post('/composable-dashboards', payload)
}

export function updateComposableDashboardDraft(id, payload) {
  return api.put(`/composable-dashboards/${id}/draft`, payload)
}

export function validateComposableDashboard(id) {
  return api.post(`/composable-dashboards/${id}/validate`)
}

export function previewComposableDashboard(id, payload = {}) {
  return api.post(`/composable-dashboards/${id}/preview`, payload)
}

export function publishComposableDashboard(id, payload = {}) {
  return api.post(`/composable-dashboards/${id}/publish`, payload)
}

export function fetchMyComposableDashboard(params = {}) {
  const query = new URLSearchParams(params).toString()

  return api.get(`/me/composable-dashboard${query ? `?${query}` : ''}`)
}
