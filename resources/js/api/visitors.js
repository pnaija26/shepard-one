import api from './client'

export default {
  listVisitors(params = {}) {
    return api.get('/visitors', { params })
  },

  getVisitor(id) {
    return api.get(`/visitors/${id}`)
  },

  captureVisitor(payload) {
    return api.post('/visitors', payload)
  },

  recordVisit(visitorId, payload) {
    return api.post(`/visitors/${visitorId}/visits`, payload)
  },

  exportVisitors(params = {}) {
    return api.get('/visitors/export', { params, responseType: 'blob' })
  },
}
