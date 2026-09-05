import api from './client'

export default {
  list(teamId, params = {}) {
    return api.get(`/service-teams/${teamId}/reports`, { params })
  },

  metrics(teamId) {
    return api.get(`/service-teams/${teamId}/report-metrics`)
  },

  create(teamId, payload) {
    return api.post(`/service-teams/${teamId}/reports`, payload)
  },

  show(reportId) {
    return api.get(`/team-reports/${reportId}`)
  },

  update(reportId, payload) {
    return api.put(`/team-reports/${reportId}`, payload)
  },

  submit(reportId) {
    return api.post(`/team-reports/${reportId}/submit`)
  },

  review(reportId, payload) {
    return api.post(`/team-reports/${reportId}/review`, payload)
  },
}
