import api from './client'

export default {
  getMovements() {
    return api.get('/org/movements')
  },

  createMovement(movementData) {
    return api.post('/org/movements', movementData)
  },

  getMovement(id) {
    return api.get(`/org/movements/${id}`)
  },

  approveMovement(id, reason = null) {
    return api.post(`/org/movements/${id}/approve`, { reason })
  },

  rejectMovement(id, reason) {
    return api.post(`/org/movements/${id}/reject`, { reason })
  },

  // People picker for the "initiate movement" form (scoped server-side).
  getPeople() {
    return api.get('/org/people')
  }
}
