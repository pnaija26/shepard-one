import api from './client'

export default {
  list() {
    return api.get('/workflows')
  },

  create(payload) {
    return api.post('/workflows', payload)
  },

  show(id) {
    return api.get(`/workflows/${id}`)
  },

  updateDraft(id, payload) {
    return api.put(`/workflows/${id}/draft`, payload)
  },

  visualize(id) {
    return api.get(`/workflows/${id}/visualize`)
  },

  validate(id) {
    return api.post(`/workflows/${id}/validate`)
  },

  test(id, payload) {
    return api.post(`/workflows/${id}/test`, payload)
  },

  publish(id, payload = {}) {
    return api.post(`/workflows/${id}/publish`, payload)
  },

  startInstance(workflowId, payload) {
    return api.post(`/workflows/${workflowId}/instances`, payload)
  },

  listInstances(params = {}) {
    return api.get('/workflow-instances', { params })
  },

  showInstance(id) {
    return api.get(`/workflow-instances/${id}`)
  },

  act(id, payload) {
    return api.post(`/workflow-instances/${id}/act`, payload)
  },

  processDeadlines(params = {}) {
    return api.post('/workflow-instances/process-deadlines', null, { params })
  },
}
