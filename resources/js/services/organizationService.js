import api from '@/api/organization'

const organizationService = {
  getOrganizations() {
    return api.get('/org/organizations')
  },

  createOrganization(organizationData) {
    return api.post('/org/organizations', organizationData)
  },

  getOrganization(id) {
    return api.get(`/org/organizations/${id}`)
  },

  updateOrganization(id, organizationData) {
    return api.put(`/org/organizations/${id}`, organizationData)
  },

  deleteOrganization(id) {
    return api.delete(`/org/organizations/${id}`)
  }
}

export default organizationService