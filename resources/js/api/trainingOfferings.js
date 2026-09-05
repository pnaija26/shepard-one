import api from './client'

export default {
  list(params = {}) {
    return api.get('/training-offerings', { params })
  },

  create(payload) {
    return api.post('/training-offerings', payload)
  },

  show(id) {
    return api.get(`/training-offerings/${id}`)
  },

  update(id, payload) {
    return api.put(`/training-offerings/${id}`, payload)
  },

  publish(id) {
    return api.post(`/training-offerings/${id}/publish`)
  },

  enrol(offeringId, payload) {
    return api.post(`/training-offerings/${offeringId}/enrol`, payload)
  },

  listEnrolments(offeringId) {
    return api.get(`/training-offerings/${offeringId}/enrolments`)
  },

  showEnrolment(id) {
    return api.get(`/training-enrolments/${id}`)
  },
}
