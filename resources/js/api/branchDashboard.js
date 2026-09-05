import api from './client'

export function listMyBranchDashboardBranches() {
  return api.get('/me/branch-dashboard/branches')
}

export function fetchBranchDashboard(branchId, params = {}) {
  const query = new URLSearchParams(params).toString()

  return api.get(`/org/organizations/${branchId}/dashboard${query ? `?${query}` : ''}`)
}

export function fetchBranchDashboardDrillDown(branchId, metric, params = {}) {
  const query = new URLSearchParams(params).toString()

  return api.get(`/org/organizations/${branchId}/dashboard/drill-down/${metric}${query ? `?${query}` : ''}`)
}
