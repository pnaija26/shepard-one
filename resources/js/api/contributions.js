import api from './client'

export default {
  list(params = {}) {
    return api.get('/contributions', { params })
  },

  show(id) {
    return api.get(`/contributions/${id}`)
  },

  createManual(payload) {
    return api.post('/contributions/manual', payload)
  },

  match(id, payload) {
    return api.post(`/contributions/${id}/match`, payload)
  },

  needsResolution(id, payload) {
    return api.post(`/contributions/${id}/needs-resolution`, payload)
  },

  reconcile(id, payload = {}) {
    return api.post(`/contributions/${id}/reconcile`, payload)
  },

  correct(id, payload) {
    return api.post(`/contributions/${id}/correct`, payload)
  },

  issueReceipt(id, payload = {}) {
    return api.post(`/contributions/${id}/receipts`, payload)
  },

  voidReceipt(receiptId, payload) {
    return api.post(`/receipts/${receiptId}/void`, payload)
  },

  campaigns() {
    return api.get('/contributions/campaigns')
  },

  createCampaign(payload) {
    return api.post('/contributions/campaigns', payload)
  },
}
