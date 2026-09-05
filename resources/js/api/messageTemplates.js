import api from './client'

export default {
  list() {
    return api.get('/message-templates')
  },

  create(payload) {
    return api.post('/message-templates', payload)
  },

  show(id) {
    return api.get(`/message-templates/${id}`)
  },

  updateDraft(id, payload) {
    return api.put(`/message-templates/${id}/draft`, payload)
  },

  validate(id) {
    return api.post(`/message-templates/${id}/validate`)
  },

  preview(id, payload = {}) {
    return api.post(`/message-templates/${id}/preview`, payload)
  },

  publish(id, payload = {}) {
    return api.post(`/message-templates/${id}/publish`, payload)
  },

  retire(id) {
    return api.post(`/message-templates/${id}/retire`)
  },
}
