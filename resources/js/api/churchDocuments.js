import api from './client'

export function fetchChurchDocumentCatalog() {
  return api.get('/church-documents/catalog')
}

export function listChurchDocuments(params = {}) {
  return api.get('/church-documents', { params })
}

export function uploadChurchDocument(payload) {
  return api.post('/church-documents', payload)
}

export function fetchChurchDocument(id) {
  return api.get(`/church-documents/${id}`)
}

export function fetchChurchDocumentByReference(reference) {
  return api.get(`/church-documents/reference/${reference}`)
}

export function listChurchDocumentVersions(id) {
  return api.get(`/church-documents/${id}/versions`)
}

export function replaceChurchDocumentVersion(id, payload) {
  return api.post(`/church-documents/${id}/versions`, payload)
}

export function issueChurchDocumentAccess(id, payload) {
  return api.post(`/church-documents/${id}/access`, payload)
}

export function downloadChurchDocument(id, params) {
  return api.get(`/church-documents/${id}/download`, { params, responseType: 'blob' })
}

export function requestChurchDocumentArchive(id) {
  return api.post(`/church-documents/${id}/archive-request`)
}
