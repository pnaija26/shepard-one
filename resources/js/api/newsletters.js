import api from './client'

export default {
  list() {
    return api.get('/newsletters')
  },

  create(payload) {
    return api.post('/newsletters', payload)
  },

  show(id) {
    return api.get(`/newsletters/${id}`)
  },

  updateDraft(id, payload) {
    return api.put(`/newsletters/${id}/draft`, payload)
  },

  validate(id) {
    return api.post(`/newsletters/${id}/validate`)
  },

  preview(id, payload = {}) {
    return api.post(`/newsletters/${id}/preview`, payload)
  },

  sendTest(id, payload) {
    return api.post(`/newsletters/${id}/test-send`, payload)
  },

  submit(id) {
    return api.post(`/newsletters/${id}/submit`)
  },

  approve(id, payload = {}) {
    return api.post(`/newsletters/${id}/approve`, payload)
  },

  processDue(params = {}) {
    return api.post('/newsletters/process-due', null, { params })
  },

  recordEvent(id, payload) {
    return api.post(`/newsletters/${id}/events`, payload)
  },

  analytics(id) {
    return api.get(`/newsletters/${id}/analytics`)
  },
}
