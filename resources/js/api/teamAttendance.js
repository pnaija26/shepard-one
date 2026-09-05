import api from './client'

export default {
  listOccurrences(teamId) {
    return api.get(`/service-teams/${teamId}/occurrences`)
  },

  createOccurrence(teamId, payload) {
    return api.post(`/service-teams/${teamId}/occurrences`, payload)
  },

  showOccurrence(occurrenceId) {
    return api.get(`/team-occurrences/${occurrenceId}`)
  },

  capture(occurrenceId, entries) {
    return api.post(`/team-occurrences/${occurrenceId}/attendance`, { entries })
  },

  correct(recordId, payload) {
    return api.post(`/team-attendance/${recordId}/correct`, payload)
  },

  analyze(teamId, params = {}) {
    return api.get(`/service-teams/${teamId}/attendance-analysis`, { params })
  },
}
