import api from './client'

export default {
  listHouseholds() {
    return api.get('/households')
  },

  getHousehold(id) {
    return api.get(`/households/${id}`)
  },

  createHousehold(payload) {
    return api.post('/households', payload)
  },

  updateHousehold(id, payload) {
    return api.put(`/households/${id}`, payload)
  },

  addMember(householdId, payload) {
    return api.post(`/households/${householdId}/members`, payload)
  },

  changeRelationship(householdId, memberId, relationshipType) {
    return api.post(`/households/${householdId}/members/${memberId}/relationship`, {
      relationship_type: relationshipType,
    })
  },

  removeMember(householdId, memberId, reason = null) {
    return api.post(`/households/${householdId}/members/${memberId}/remove`, { reason })
  },
}
