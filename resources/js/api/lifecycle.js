import api from './client'

export default {
  getLifecycle(memberId) {
    return api.get(`/members/${memberId}/lifecycle`)
  },

  transition(memberId, payload) {
    return api.post(`/members/${memberId}/lifecycle/transition`, payload)
  },

  listPendingTransitions() {
    return api.get('/members/lifecycle/pending')
  },

  approvePending(id) {
    return api.post(`/members/lifecycle/pending/${id}/approve`)
  },

  rejectPending(id, reason = null) {
    return api.post(`/members/lifecycle/pending/${id}/reject`, { reason })
  },
}
