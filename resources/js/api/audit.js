import api from './client'

export default {
  listEvents(params = {}) {
    return api.get('/audit', { params })
  },

  getEvent(id) {
    return api.get(`/audit/${id}`)
  },

  exportEvents(params = {}) {
    return api.get('/audit/export', { params })
  },
}
