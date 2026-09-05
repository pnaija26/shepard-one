import api from './client'

export default {
  listFollowUps(params = {}) {
    return api.get('/follow-ups', { params })
  },

  createFollowUp(payload) {
    return api.post('/follow-ups', payload)
  },

  getFollowUp(id) {
    return api.get(`/follow-ups/${id}`)
  },

  recordActivity(id, payload) {
    return api.post(`/follow-ups/${id}/activities`, payload)
  },

  processEscalations(params = {}) {
    return api.post('/follow-ups/process-escalations', null, { params })
  },
}
