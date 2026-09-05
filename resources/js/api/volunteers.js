import api from './client'

export default {
  list(params = {}) {
    return api.get('/volunteers', { params })
  },

  alerts() {
    return api.get('/volunteers/alerts')
  },

  create(payload) {
    return api.post('/volunteers', payload)
  },

  show(id) {
    return api.get(`/volunteers/${id}`)
  },

  update(id, payload) {
    return api.put(`/volunteers/${id}`, payload)
  },

  verifyChange(changeId, payload) {
    return api.post(`/volunteers/changes/${changeId}/verify`, payload)
  },

  myProfile() {
    return api.get('/me/volunteer-profile')
  },

  updateMyProfile(payload) {
    return api.put('/me/volunteer-profile', payload)
  },

  recommendForTeam(teamId, payload) {
    return api.post(`/service-teams/${teamId}/volunteer-recommendations`, payload)
  },

  confirmRecommendation(teamId, payload) {
    return api.post(`/service-teams/${teamId}/volunteer-recommendations/confirm`, payload)
  },
}
