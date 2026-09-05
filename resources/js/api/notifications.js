import api from './client'

export default {
  list(params = {}) {
    return api.get('/me/notifications', { params })
  },

  summary() {
    return api.get('/me/notifications/summary')
  },

  show(id) {
    return api.get(`/me/notifications/${id}`)
  },

  markRead(id) {
    return api.post(`/me/notifications/${id}/read`)
  },

  markUnread(id) {
    return api.post(`/me/notifications/${id}/unread`)
  },

  archive(id) {
    return api.post(`/me/notifications/${id}/archive`)
  },

  unarchive(id) {
    return api.post(`/me/notifications/${id}/unarchive`)
  },

  markAllRead() {
    return api.post('/me/notifications/mark-all-read')
  },

  open(id) {
    return api.post(`/me/notifications/${id}/open`)
  },
}
