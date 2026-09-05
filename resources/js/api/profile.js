import api from './client'

export default {
  getProfile() {
    return api.get('/me/profile')
  },

  updateProfile(payload) {
    return api.put('/me/profile', payload)
  },

  listPendingChanges() {
    return api.get('/members/profile-changes')
  },

  approveChange(id) {
    return api.post(`/members/profile-changes/${id}/approve`)
  },

  rejectChange(id, reason = null) {
    return api.post(`/members/profile-changes/${id}/reject`, { reason })
  },
}
