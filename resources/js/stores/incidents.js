import { defineStore } from 'pinia'
import incidentsApi from '../api/incidents'
import { extractApiError } from '../api/client'

export const useIncidentsStore = defineStore('incidents', {
  state: () => ({
    items: [],
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchIncidents(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await incidentsApi.list(params)
        this.items = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load incidents')
      } finally {
        this.loading = false
      }
    },

    async reportIncident(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await incidentsApi.report(payload)
        await this.fetchIncidents()
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to report incident')
        throw error
      } finally {
        this.saving = false
      }
    },

    async recordActivity(id, payload) {
      this.saving = true
      this.error = null
      try {
        await incidentsApi.recordActivity(id, payload)
        await this.fetchIncidents()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to update incident')
        throw error
      } finally {
        this.saving = false
      }
    },

    async reviewIncident(id, payload) {
      this.saving = true
      this.error = null
      try {
        await incidentsApi.review(id, payload)
        await this.fetchIncidents()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to review incident')
        throw error
      } finally {
        this.saving = false
      }
    },

    async processEscalations(branchId = null) {
      this.saving = true
      this.error = null
      try {
        await incidentsApi.processEscalations(branchId ? { branch_id: branchId } : {})
        await this.fetchIncidents()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to process escalations')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
