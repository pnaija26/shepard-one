import api from './client'

export default {
  listRules() {
    return api.get('/attendance/rules')
  },

  createRule(payload) {
    return api.post('/attendance/rules', payload)
  },

  publishRule(id, payload) {
    return api.post(`/attendance/rules/${id}/publish`, payload)
  },

  listExceptions(params = {}) {
    return api.get('/attendance/exceptions', { params })
  },

  recordAttendance(payload) {
    return api.post('/attendance/records', payload)
  },

  captureAttendance(payload) {
    return api.post('/attendance/capture', payload)
  },

  syncAttendance(entries) {
    return api.post('/attendance/sync', { entries })
  },

  listSessionRecords(sessionKey, sessionId) {
    return api.get(`/attendance/sessions/${sessionKey}/${sessionId}/records`)
  },
}
