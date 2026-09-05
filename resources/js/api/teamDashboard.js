import api from './client'

export function listMyTeamDashboardTeams() {
  return api.get('/me/team-dashboard/teams')
}

export function fetchTeamDashboard(teamId) {
  return api.get(`/service-teams/${teamId}/dashboard`)
}

export function fetchTeamDashboardDrillDown(teamId, widget, params = {}) {
  const query = new URLSearchParams(params).toString()

  return api.get(`/service-teams/${teamId}/dashboard/drill-down/${widget}${query ? `?${query}` : ''}`)
}

export function syncTeamDashboard(teamId, payload = {}) {
  return api.post(`/service-teams/${teamId}/dashboard/sync`, payload)
}
