import api from './client'

export default {
  list() {
    return api.get('/automation-rules')
  },

  create(payload) {
    return api.post('/automation-rules', payload)
  },

  show(id) {
    return api.get(`/automation-rules/${id}`)
  },

  updateDraft(id, payload) {
    return api.put(`/automation-rules/${id}/draft`, payload)
  },

  validate(id) {
    return api.post(`/automation-rules/${id}/validate`)
  },

  simulate(id, payload) {
    return api.post(`/automation-rules/${id}/simulate`, payload)
  },

  publish(id) {
    return api.post(`/automation-rules/${id}/publish`)
  },

  setEnabled(id, enabled) {
    return api.post(`/automation-rules/${id}/enabled`, { enabled })
  },

  evaluate(payload) {
    return api.post('/automation-rules/evaluate', payload)
  },

  processRetries(params = {}) {
    return api.post('/automation-rules/process-retries', null, { params })
  },
}
