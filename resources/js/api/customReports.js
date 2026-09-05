import api from './client'

export function fetchCustomReportCatalog() {
  return api.get('/custom-reports/catalog')
}

export function listCustomReports() {
  return api.get('/custom-reports')
}

export function createCustomReport(payload) {
  return api.post('/custom-reports', payload)
}

export function updateCustomReportDraft(id, payload) {
  return api.put(`/custom-reports/${id}/draft`, payload)
}

export function validateCustomReport(id) {
  return api.post(`/custom-reports/${id}/validate`)
}

export function previewCustomReport(id, payload = {}) {
  return api.post(`/custom-reports/${id}/preview`, payload)
}

export function publishCustomReport(id, payload = {}) {
  return api.post(`/custom-reports/${id}/publish`, payload)
}

export function runCustomReport(id, params = {}) {
  const query = new URLSearchParams(params).toString()

  return api.get(`/custom-reports/${id}/run${query ? `?${query}` : ''}`)
}
