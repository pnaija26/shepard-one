import api from './client'

export default {
  list(params = {}) {
    return api.get('/care-cases', { params })
  },

  create(payload) {
    return api.post('/care-cases', payload)
  },

  show(id) {
    return api.get(`/care-cases/${id}`)
  },

  recordActivity(id, payload) {
    return api.post(`/care-cases/${id}/activities`, payload)
  },

  escalate(id, payload) {
    return api.post(`/care-cases/${id}/escalate`, payload)
  },

  processEscalations(payload = {}) {
    return api.post('/care-cases/process-escalations', payload)
  },

  acknowledgeEscalation(escalationId) {
    return api.post(`/care-case-escalations/${escalationId}/acknowledge`)
  },

  close(id, payload) {
    return api.post(`/care-cases/${id}/close`, payload)
  },

  reopen(id, payload) {
    return api.post(`/care-cases/${id}/reopen`, payload)
  },
}
