import api from './client'

export default {
  getSettings() {
    return api.get('/me/directory-settings')
  },

  updateSettings(payload) {
    return api.put('/me/directory-settings', payload)
  },

  search(params = {}) {
    return api.get('/directory', { params })
  },

  getMember(id) {
    return api.get(`/directory/${id}`)
  },

  exportDirectory(params = {}) {
    return api.get('/directory/export', { params, responseType: 'blob' })
  },
}
