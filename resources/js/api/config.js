import api from './client'

export default {
  listSettings(category = null) {
    return api.get('/config', { params: category ? { category } : {} })
  },

  updateSetting(key, payload) {
    return api.put(`/config/${key}`, payload)
  },

  publishSetting(key) {
    return api.post(`/config/${key}/publish`)
  },

  deleteSetting(key, archive = false) {
    return api.delete(`/config/${key}`, { data: { archive } })
  },

  listCategories() {
    return api.get('/config/categories')
  },

  createCategory(payload) {
    return api.post('/config/categories', payload)
  },
}
