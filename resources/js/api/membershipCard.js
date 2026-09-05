import api from './client'

export default {
  getMyCard() {
    return api.get('/me/membership-card')
  },

  listPurposes() {
    return api.get('/membership-card/purposes')
  },

  verify(token, purpose) {
    return api.post('/membership-card/verify', { token, purpose })
  },
}
