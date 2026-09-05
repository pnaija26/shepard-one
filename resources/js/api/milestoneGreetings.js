import api from './client'

export default {
  listConfigs() {
    return api.get('/milestone-greetings/configs')
  },

  upsertConfig(payload) {
    return api.post('/milestone-greetings/configs', payload)
  },

  today(params = {}) {
    return api.get('/milestone-greetings/today', { params })
  },

  evaluations(params = {}) {
    return api.get('/milestone-greetings/evaluations', { params })
  },

  process(payload = {}) {
    return api.post('/milestone-greetings/process', payload)
  },

  upsertMemberMilestone(memberId, payload) {
    return api.post(`/milestone-greetings/members/${memberId}/milestones`, payload)
  },
}
