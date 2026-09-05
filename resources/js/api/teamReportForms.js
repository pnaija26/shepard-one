import api from './client'

export function listTeamReportForms() {
  return api.get('/team-report-forms')
}

export function createTeamReportForm(payload) {
  return api.post('/team-report-forms', payload)
}

export function fetchTeamReportForm(formId) {
  return api.get(`/team-report-forms/${formId}`)
}

export function updateTeamReportFormDraft(formId, payload) {
  return api.put(`/team-report-forms/${formId}/draft`, payload)
}

export function previewTeamReportForm(formId) {
  return api.get(`/team-report-forms/${formId}/preview`)
}

export function publishTeamReportForm(formId, payload) {
  return api.post(`/team-report-forms/${formId}/publish`, payload)
}

export function fetchTeamReportFormForTeam(teamId) {
  return api.get(`/service-teams/${teamId}/report-form`)
}
