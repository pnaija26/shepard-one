import { defineStore } from 'pinia'
import * as operationsApi from '../api/operationsMonitoring'
import { extractApiError } from '../api/client'

export const useOperationsMonitoringStore = defineStore('operationsMonitoring', {
  state: () => ({
    catalog: null,
    dashboard: null,
    backups: [],
    recoveryExercises: [],
    collectResult: null,
    saving: false,
    error: null,
  }),

  actions: {
    async loadCatalog() {
      const { data } = await operationsApi.fetchOperationsCatalog()
      this.catalog = data.data
    },

    async loadDashboard() {
      const { data } = await operationsApi.fetchOperationsDashboard()
      this.dashboard = data.data
    },

    async collectTelemetry() {
      this.saving = true
      this.error = null
      try {
        const { data } = await operationsApi.collectOperationsTelemetry()
        this.collectResult = data.data
        await this.loadDashboard()
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to collect telemetry.')
        throw error
      } finally {
        this.saving = false
      }
    },

    async acknowledgeAlert(id) {
      await operationsApi.acknowledgeOperationsAlert(id)
      await this.loadDashboard()
    },

    async resolveAlert(id) {
      await operationsApi.resolveOperationsAlert(id)
      await this.loadDashboard()
    },

    async loadBackups() {
      const { data } = await operationsApi.listBackupRuns()
      this.backups = data.data ?? []
    },

    async recordBackup(payload) {
      this.saving = true
      this.error = null
      try {
        const { data } = await operationsApi.recordBackupRun(payload)
        await Promise.all([this.loadBackups(), this.loadDashboard()])
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to record backup.')
        throw error
      } finally {
        this.saving = false
      }
    },

    async loadRecoveryExercises() {
      const { data } = await operationsApi.listRecoveryExercises()
      this.recoveryExercises = data.data ?? []
    },

    async recordRecoveryExercise(payload) {
      this.saving = true
      this.error = null
      try {
        const { data } = await operationsApi.recordRecoveryExercise(payload)
        await Promise.all([this.loadRecoveryExercises(), this.loadDashboard()])
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to record recovery exercise.')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
