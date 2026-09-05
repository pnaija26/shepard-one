import api from './client'

export default {
  listJourneys() {
    return api.get('/onboarding/journeys')
  },

  createJourney(payload) {
    return api.post('/onboarding/journeys', payload)
  },

  publishJourney(id, payload) {
    return api.post(`/onboarding/journeys/${id}/publish`, payload)
  },

  listEnrollments(params = {}) {
    return api.get('/onboarding/enrollments', { params })
  },

  getEnrollment(id) {
    return api.get(`/onboarding/enrollments/${id}`)
  },

  processDue() {
    return api.post('/onboarding/process-due')
  },
}
