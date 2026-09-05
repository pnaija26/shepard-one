import api from './client'

export function fetchReportExportCatalog() {
  return api.get('/report-exports/catalog')
}

export function requestReportExport(payload) {
  return api.post('/report-exports', payload)
}

export function fetchReportExportStatus(reference) {
  return api.get(`/report-exports/${reference}/status`)
}

export function downloadReportExport(reference, token) {
  return api.get(`/report-exports/${reference}/download`, {
    params: { token },
    responseType: 'blob',
  })
}
