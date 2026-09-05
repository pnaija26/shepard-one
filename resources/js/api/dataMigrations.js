import api from './client'

export function fetchDataMigrationCatalog() {
  return api.get('/data-migrations/catalog')
}

export function listDataMigrationSources() {
  return api.get('/data-migrations/sources')
}

export function createDataMigrationSource(payload) {
  return api.post('/data-migrations/sources', payload)
}

export function profileDataMigrationSource(id) {
  return api.post(`/data-migrations/sources/${id}/profile`)
}

export function createDataMigrationMapping(sourceId, payload) {
  return api.post(`/data-migrations/sources/${sourceId}/mappings`, payload)
}

export function validateDataMigrationMapping(id) {
  return api.post(`/data-migrations/mappings/${id}/validate`)
}

export function runDataMigrationValidation(id) {
  return api.post(`/data-migrations/mappings/${id}/validate-run`)
}

export function approveDataMigrationMapping(id) {
  return api.post(`/data-migrations/mappings/${id}/approve`)
}

export function createDataMigrationCutoverPlan(mappingId, payload) {
  return api.post(`/data-migrations/mappings/${mappingId}/cutover-plans`, payload)
}

export function fetchDataMigrationCutoverPlan(id) {
  return api.get(`/data-migrations/cutover-plans/${id}`)
}

export function runDataMigrationTest(id, payload = {}) {
  return api.post(`/data-migrations/cutover-plans/${id}/test-run`, payload)
}

export function signOffDataMigrationUat(id) {
  return api.post(`/data-migrations/cutover-plans/${id}/uat-sign-off`)
}

export function executeDataMigrationProduction(id, payload = {}) {
  return api.post(`/data-migrations/cutover-plans/${id}/execute-production`, payload)
}

export function approveDataMigrationGoLive(id) {
  return api.post(`/data-migrations/cutover-plans/${id}/go-live`)
}

export function disposeDataMigration(id) {
  return api.post(`/data-migrations/cutover-plans/${id}/dispose`)
}

export function fetchDataMigrationRun(id) {
  return api.get(`/data-migrations/runs/${id}`)
}
