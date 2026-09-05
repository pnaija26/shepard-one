import api from './client'

export function fetchGlobalSearchCatalog() {
  return api.get('/global-search/catalog')
}

export function searchGlobalRecords(query, params = {}) {
  return api.get('/global-search', { params: { q: query, ...params } })
}

export function resolveGlobalSearchRecord(recordType, recordId) {
  return api.get(`/global-search/resolve/${recordType}/${recordId}`)
}

export function listGlobalSearchSyncFailures() {
  return api.get('/global-search/sync-failures')
}

export function processGlobalSearchRetries() {
  return api.post('/global-search/process-retries')
}
