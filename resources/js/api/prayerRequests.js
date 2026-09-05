import api from './client'

export default {
  list(params = {}) {
    return api.get('/prayer-requests', { params })
  },

  myRequests() {
    return api.get('/me/prayer-requests')
  },

  create(payload) {
    return api.post('/prayer-requests', payload)
  },

  createMine(payload) {
    return api.post('/me/prayer-requests', payload)
  },

  show(id) {
    return api.get(`/prayer-requests/${id}`)
  },

  updateConfidentiality(id, payload) {
    return api.post(`/prayer-requests/${id}/confidentiality`, payload)
  },

  withdraw(id, payload = {}) {
    return api.post(`/prayer-requests/${id}/withdraw`, payload)
  },

  assign(id, payload) {
    return api.post(`/prayer-requests/${id}/assign`, payload)
  },

  acknowledge(id, payload = {}) {
    return api.post(`/prayer-requests/${id}/acknowledge`, payload)
  },

  recordUpdate(id, payload) {
    return api.post(`/prayer-requests/${id}/updates`, payload)
  },

  escalate(id, payload) {
    return api.post(`/prayer-requests/${id}/escalate`, payload)
  },

  markAnswered(id, payload = {}) {
    return api.post(`/prayer-requests/${id}/answer`, payload)
  },

  close(id, payload = {}) {
    return api.post(`/prayer-requests/${id}/close`, payload)
  },

  publishToGroup(id, payload = {}) {
    return api.post(`/prayer-requests/${id}/publish-to-group`, payload)
  },
}
