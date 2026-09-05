import api from './client'

export default {
  list(params = {}) {
    return api.get('/tasks', { params })
  },

  create(payload) {
    return api.post('/tasks', payload)
  },

  show(id) {
    return api.get(`/tasks/${id}`)
  },

  changeStatus(id, payload) {
    return api.post(`/tasks/${id}/status`, payload)
  },

  reassign(id, payload) {
    return api.post(`/tasks/${id}/reassign`, payload)
  },

  processOverdue(params = {}) {
    return api.post('/tasks/process-overdue', null, { params })
  },
}
