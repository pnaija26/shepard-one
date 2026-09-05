import api from './client'

export default {
  listRoles() {
    return api.get('/roles')
  },

  getRole(id) {
    return api.get(`/roles/${id}`)
  },

  createRole(payload) {
    return api.post('/roles', payload)
  },

  updateRole(id, payload) {
    return api.put(`/roles/${id}`, payload)
  },

  deleteRole(id, payload = {}) {
    return api.delete(`/roles/${id}`, { data: payload })
  },

  assignRole(roleId, userId, expiresAt = null) {
    return api.post(`/roles/${roleId}/assignments`, {
      user_id: userId,
      expires_at: expiresAt,
    })
  },

  revokeAssignment(roleId, userId) {
    return api.delete(`/roles/${roleId}/assignments/${userId}`)
  },
}
