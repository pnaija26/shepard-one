import api from './client'

export function fetchReportScheduleCatalog() {
  return api.get('/report-schedules/catalog')
}

export function listReportSchedules() {
  return api.get('/report-schedules')
}

export function createReportSchedule(payload) {
  return api.post('/report-schedules', payload)
}

export function fetchReportSchedule(id) {
  return api.get(`/report-schedules/${id}`)
}
