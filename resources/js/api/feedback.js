import api from './client'

export default {
  submit(payload) {
    return api.post('/me/feedback', payload)
  },

  list(params = {}) {
    return api.get('/feedback', { params })
  },

  show(id) {
    return api.get(`/feedback/${id}`)
  },

  recordActivity(id, payload) {
    return api.post(`/feedback/${id}/activities`, payload)
  },
}
