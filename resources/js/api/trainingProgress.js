import api from './client'

export default {
  show(enrolmentId) {
    return api.get(`/training-enrolments/${enrolmentId}/progress`)
  },

  recordAttendance(enrolmentId, payload) {
    return api.post(`/training-enrolments/${enrolmentId}/attendance`, payload)
  },

  recordAssessments(enrolmentId, payload) {
    return api.post(`/training-enrolments/${enrolmentId}/assessments`, payload)
  },

  confirmCompletion(enrolmentId) {
    return api.post(`/training-enrolments/${enrolmentId}/confirm-completion`)
  },

  verifyCertificate(reference) {
    return api.get(`/training-certificates/verify/${reference}`)
  },
}
