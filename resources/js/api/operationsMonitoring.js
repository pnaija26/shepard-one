import api from './client'

export function fetchOperationsCatalog() {
  return api.get('/operations-monitoring/catalog')
}

export function fetchOperationsDashboard() {
  return api.get('/operations-monitoring/dashboard')
}

export function collectOperationsTelemetry() {
  return api.post('/operations-monitoring/collect-telemetry')
}

export function acknowledgeOperationsAlert(id) {
  return api.post(`/operations-monitoring/alerts/${id}/acknowledge`)
}

export function resolveOperationsAlert(id) {
  return api.post(`/operations-monitoring/alerts/${id}/resolve`)
}

export function listBackupRuns() {
  return api.get('/operations-monitoring/backups')
}

export function recordBackupRun(payload) {
  return api.post('/operations-monitoring/backups', payload)
}

export function listRecoveryExercises() {
  return api.get('/operations-monitoring/recovery-exercises')
}

export function recordRecoveryExercise(payload) {
  return api.post('/operations-monitoring/recovery-exercises', payload)
}
