import api from './client'

export default {
  listEvents(params = {}) {
    return api.get('/events', { params })
  },

  createEvent(payload) {
    return api.post('/events', payload)
  },

  publishEvent(id) {
    return api.post(`/events/${id}/publish`)
  },

  postponeEvent(id, payload) {
    return api.post(`/events/${id}/postpone`, payload)
  },

  completeEvent(id) {
    return api.post(`/events/${id}/complete`)
  },

  closeEvent(id) {
    return api.post(`/events/${id}/close`)
  },

  scanAdmission(payload) {
    return api.post('/event-admissions/scan', payload)
  },

  registerForEvent(eventId, payload) {
    return api.post(`/events/${eventId}/registrations`, payload)
  },
}
