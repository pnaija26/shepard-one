import api from './client'

export default {
  list(teamId) {
    return api.get(`/service-teams/${teamId}/rosters`)
  },

  create(teamId, payload) {
    return api.post(`/service-teams/${teamId}/rosters`, payload)
  },

  show(rosterId) {
    return api.get(`/team-rosters/${rosterId}`)
  },

  addSlot(rosterId, payload) {
    return api.post(`/team-rosters/${rosterId}/slots`, payload)
  },

  validate(rosterId) {
    return api.post(`/team-rosters/${rosterId}/validate`)
  },

  publish(rosterId, payload = {}) {
    return api.post(`/team-rosters/${rosterId}/publish`, payload)
  },

  substitute(slotId, payload) {
    return api.post(`/roster-slots/${slotId}/substitute`, payload)
  },

  mySlots() {
    return api.get('/me/roster-slots')
  },

  respond(slotId, payload) {
    return api.post(`/me/roster-slots/${slotId}/respond`, payload)
  },
}
