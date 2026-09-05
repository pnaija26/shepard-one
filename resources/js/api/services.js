import api from './client'

export default {
  listServices(params = {}) {
    return api.get('/services', { params })
  },

  createService(payload) {
    return api.post('/services', payload)
  },

  updateService(id, payload) {
    return api.put(`/services/${id}`, payload)
  },

  publishService(id) {
    return api.post(`/services/${id}/publish`)
  },

  cancelService(id, payload = {}) {
    return api.post(`/services/${id}/cancel`, payload)
  },
}
