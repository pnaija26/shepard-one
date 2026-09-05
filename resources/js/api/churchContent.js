import api from './client'

export default {
  list() {
    return api.get('/church-content')
  },

  create(payload) {
    return api.post('/church-content', payload)
  },

  show(id) {
    return api.get(`/church-content/${id}`)
  },

  updateDraft(id, payload) {
    return api.put(`/church-content/${id}/draft`, payload)
  },

  validate(id) {
    return api.post(`/church-content/${id}/validate`)
  },

  preview(id, payload = {}) {
    return api.post(`/church-content/${id}/preview`, payload)
  },

  submit(id) {
    return api.post(`/church-content/${id}/submit`)
  },

  approve(id, payload = {}) {
    return api.post(`/church-content/${id}/approve`, payload)
  },

  withdraw(id, payload = {}) {
    return api.post(`/church-content/${id}/withdraw`, payload)
  },

  processWindows(params = {}) {
    return api.post('/church-content/process-windows', null, { params })
  },

  feed(params = {}) {
    return api.get('/church-content/feed', { params })
  },

  search(params = {}) {
    return api.get('/church-content/search', { params })
  },
}
