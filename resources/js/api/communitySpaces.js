import api from './client'

export default {
  list() {
    return api.get('/community-spaces')
  },

  create(payload) {
    return api.post('/community-spaces', payload)
  },

  show(id) {
    return api.get(`/community-spaces/${id}`)
  },

  addMember(id, payload) {
    return api.post(`/community-spaces/${id}/members`, payload)
  },

  messages(id, params = {}) {
    return api.get(`/community-spaces/${id}/messages`, { params })
  },

  postMessage(id, payload) {
    return api.post(`/community-spaces/${id}/messages`, payload)
  },

  search(id, q) {
    return api.get(`/community-spaces/${id}/search`, { params: { q } })
  },

  pin(spaceId, messageId, payload = {}) {
    return api.post(`/community-spaces/${spaceId}/messages/${messageId}/pin`, payload)
  },

  restrict(spaceId, messageId, payload = {}) {
    return api.post(`/community-spaces/${spaceId}/messages/${messageId}/restrict`, payload)
  },

  remove(spaceId, messageId, payload = {}) {
    return api.post(`/community-spaces/${spaceId}/messages/${messageId}/remove`, payload)
  },

  report(spaceId, messageId, payload) {
    return api.post(`/community-spaces/${spaceId}/messages/${messageId}/report`, payload)
  },

  moderateParticipant(spaceId, userId, payload) {
    return api.post(`/community-spaces/${spaceId}/members/${userId}/moderate`, payload)
  },

  configureIntegration(id, payload) {
    return api.post(`/community-spaces/${id}/integrations`, payload)
  },

  purgeExpired(params = {}) {
    return api.post('/community-spaces/purge-expired', null, { params })
  },
}
