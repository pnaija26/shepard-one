import api from './client'

export function fetchStandardReportCatalog() {
  return api.get('/standard-reports/catalog')
}

export function runStandardReport(reportKey, params = {}) {
  const query = new URLSearchParams(params).toString()

  return api.get(`/standard-reports/${reportKey}${query ? `?${query}` : ''}`)
}
