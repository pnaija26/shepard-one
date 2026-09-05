import api from './client'

export default {
  list(params = {}) {
    return api.get('/incidents', { params })
  },

  report(payload) {
    return api.post('/incidents', payload)
  },

  show(id) {
    return api.get(`/incidents/${id}`)
  },

  recordActivity(id, payload) {
    return api.post(`/incidents/${id}/activities`, payload)
  },

  review(id, payload) {
    return api.post(`/incidents/${id}/review`, payload)
  },

  processEscalations(payload = {}) {
    return api.post('/incidents/process-escalations', payload)
  },
}
