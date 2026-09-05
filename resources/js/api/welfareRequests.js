import api from './client'

export default {
  list(params = {}) {
    return api.get('/welfare-requests', { params })
  },

  myRequests() {
    return api.get('/me/welfare-requests')
  },

  create(payload) {
    return api.post('/welfare-requests', payload)
  },

  createMine(payload) {
    return api.post('/me/welfare-requests', payload)
  },

  show(id) {
    return api.get(`/welfare-requests/${id}`)
  },

  update(id, payload) {
    return api.put(`/welfare-requests/${id}`, payload)
  },

  submit(id) {
    return api.post(`/welfare-requests/${id}/submit`)
  },

  assign(id, payload) {
    return api.post(`/welfare-requests/${id}/assign`, payload)
  },

  assess(id, payload) {
    return api.post(`/welfare-requests/${id}/assess`, payload)
  },

  recordCondition(id, payload) {
    return api.post(`/welfare-requests/${id}/conditions`, payload)
  },

  decide(id, payload) {
    return api.post(`/welfare-requests/${id}/decisions`, payload)
  },

  recordDelivery(id, payload) {
    return api.post(`/welfare-requests/${id}/deliveries`, payload)
  },

  confirmDelivery(deliveryId, payload) {
    return api.post(`/welfare-deliveries/${deliveryId}/confirm`, payload)
  },

  recordFollowUp(id, payload) {
    return api.post(`/welfare-requests/${id}/follow-ups`, payload)
  },

  closeRequest(id, payload) {
    return api.post(`/welfare-requests/${id}/close`, payload)
  },

  processOverdue(payload = {}) {
    return api.post('/welfare-follow-ups/process-overdue', payload)
  },

  report(params = {}) {
    return api.get('/welfare-reports', { params })
  },

  listApprovalConfigs() {
    return api.get('/welfare-approval-configs')
  },
}
