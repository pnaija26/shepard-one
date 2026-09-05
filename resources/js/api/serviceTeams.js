import api from './client'

export default {
  list(params = {}) {
    return api.get('/service-teams', { params })
  },

  create(payload) {
    return api.post('/service-teams', payload)
  },

  show(id) {
    return api.get(`/service-teams/${id}`)
  },

  update(id, payload) {
    return api.put(`/service-teams/${id}`, payload)
  },

  activate(id) {
    return api.post(`/service-teams/${id}/activate`)
  },

  archive(id, payload = {}) {
    return api.post(`/service-teams/${id}/archive`, payload)
  },

  listAssignments(teamId) {
    return api.get(`/service-teams/${teamId}/assignments`)
  },

  assignMember(teamId, payload) {
    return api.post(`/service-teams/${teamId}/assignments`, payload)
  },

  bulkAssign(teamId, entries) {
    return api.post(`/service-teams/${teamId}/assignments/bulk`, { entries })
  },

  approveAssignment(assignmentId) {
    return api.post(`/team-assignments/${assignmentId}/approve`)
  },

  transferAssignment(assignmentId, payload) {
    return api.post(`/team-assignments/${assignmentId}/transfer`, payload)
  },

  removeAssignment(assignmentId, payload = {}) {
    return api.post(`/team-assignments/${assignmentId}/remove`, payload)
  },
}
