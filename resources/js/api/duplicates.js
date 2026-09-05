import api from './client'

export default {
  listFlags(params = {}) {
    return api.get('/members/duplicate-flags', { params })
  },

  compare(flagId) {
    return api.get(`/members/duplicate-flags/${flagId}`)
  },

  dismiss(flagId) {
    return api.post(`/members/duplicate-flags/${flagId}/dismiss`)
  },

  merge(payload) {
    return api.post('/members/duplicates/merge', payload)
  },

  scan(memberId) {
    return api.post(`/members/${memberId}/scan-duplicates`)
  },
}
