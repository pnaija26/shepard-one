import { defineStore } from 'pinia'
import givingApi from '../api/giving'
import { extractApiError } from '../api/client'

export const useGivingStore = defineStore('giving', {
  state: () => ({
    history: null,
    statement: null,
    report: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchHistory(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await givingApi.history(params)
        this.history = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load giving history')
        this.history = null
      } finally {
        this.loading = false
      }
    },

    async requestStatement(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await givingApi.statement(payload)
        this.statement = response.data?.data ?? null
        return this.statement
      } catch (error) {
        this.error = extractApiError(error, 'Unable to generate statement')
        throw error
      } finally {
        this.saving = false
      }
    },

    async fetchReport(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await givingApi.report(params)
        this.report = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load giving report')
        this.report = null
      } finally {
        this.loading = false
      }
    },
  },
})
