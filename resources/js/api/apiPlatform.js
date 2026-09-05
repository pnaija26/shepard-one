import api from './client'

export function fetchApiPlatformCatalog() {
  return api.get('/platform/catalog')
}

export function fetchApiPlatformContract() {
  return api.get('/platform/contract')
}

export function validateApiPlatformContract() {
  return api.get('/platform/contract/validate')
}

export function listApiPlatformClients() {
  return api.get('/platform/clients')
}

export function createApiPlatformClient(payload) {
  return api.post('/platform/clients', payload)
}

export function revokeApiPlatformClient(id) {
  return api.post(`/platform/clients/${id}/revoke`)
}
