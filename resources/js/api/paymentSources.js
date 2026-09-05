import api from './client'

export default {
  list() {
    return api.get('/payment-sources')
  },

  create(payload) {
    return api.post('/payment-sources', payload)
  },

  show(id) {
    return api.get(`/payment-sources/${id}`)
  },

  update(id, payload) {
    return api.put(`/payment-sources/${id}`, payload)
  },

  test(id) {
    return api.post(`/payment-sources/${id}/test`)
  },

  contributions(params = {}) {
    return api.get('/payment-sources/contributions', { params })
  },

  ingest(id, payload, signature) {
    return api.post(`/payment-sources/${id}/ingest`, payload, {
      headers: signature ? { 'X-Payment-Signature': signature } : {},
    })
  },
}
