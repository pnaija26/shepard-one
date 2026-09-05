import api from './client'

export default {
  list(params = {}) {
    return api.get('/communications', { params })
  },

  create(payload) {
    return api.post('/communications', payload)
  },

  show(id, params = {}) {
    return api.get(`/communications/${id}`, { params })
  },

  cancel(id) {
    return api.post(`/communications/${id}/cancel`)
  },

  suppress(payload) {
    return api.post('/communications/suppressions', payload)
  },

  processDue(params = {}) {
    return api.post('/communications/process-due', null, { params })
  },

  processRetries(params = {}) {
    return api.post('/communications/process-retries', null, { params })
  },
}
