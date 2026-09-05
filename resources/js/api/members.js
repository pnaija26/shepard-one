import api from './client'

export default {
  listMembers(params = {}) {
    return api.get('/members', { params })
  },

  getMember(id) {
    return api.get(`/members/${id}`)
  },

  createMember(payload) {
    return api.post('/members', payload)
  },

  updateMember(id, payload) {
    return api.put(`/members/${id}`, payload)
  },

  archiveMember(id, reason = null) {
    return api.post(`/members/${id}/archive`, { reason })
  },
}
