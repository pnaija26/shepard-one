import api from './client'

export default {
  history(params = {}) {
    return api.get('/me/giving', { params })
  },

  statement(payload) {
    return api.post('/me/giving/statement', payload)
  },

  report(params = {}) {
    return api.get('/giving/reports', { params })
  },
}
