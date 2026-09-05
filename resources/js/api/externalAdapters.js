import api from './client'

export function fetchExternalAdapterCatalog() {
  return api.get('/external-adapters/catalog')
}

export function listExternalAdapters() {
  return api.get('/external-adapters')
}

export function createExternalAdapter(payload) {
  return api.post('/external-adapters', payload)
}

export function testExternalAdapter(id) {
  return api.post(`/external-adapters/${id}/test`)
}

export function activateExternalAdapter(id) {
  return api.post(`/external-adapters/${id}/activate`)
}

export function disableExternalAdapter(id, payload = {}) {
  return api.post(`/external-adapters/${id}/disable`, payload)
}

export function processDueExternalAdapters() {
  return api.post('/external-adapters/process-due')
}
