import { defineStore } from 'pinia'
import careApi from '../api/careCases'
import { extractApiError } from '../api/client'

export const useCareCasesStore = defineStore('careCases', {
  state: () => ({
    cases: [],
    selectedCase: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchCases(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await careApi.list(params)
        this.cases = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load care cases')
      } finally {
        this.loading = false
      }
    },

    async createCase(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await careApi.create(payload)
        await this.fetchCases()
        this.selectedCase = response.data?.data ?? null
        return this.selectedCase
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create care case')
        throw error
      } finally {
        this.saving = false
      }
    },

    async selectCase(id) {
      this.loading = true
      this.error = null
      try {
        const response = await careApi.show(id)
        this.selectedCase = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to open care case')
        this.selectedCase = null
        throw error
      } finally {
        this.loading = false
      }
    },

    async recordActivity(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await careApi.recordActivity(id, payload)
        this.selectedCase = response.data?.case ?? this.selectedCase
        await this.fetchCases()
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to record care activity')
        throw error
      } finally {
        this.saving = false
      }
    },

    async escalate(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await careApi.escalate(id, payload)
        this.selectedCase = response.data?.case ?? this.selectedCase
        await this.fetchCases()
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to escalate care case')
        throw error
      } finally {
        this.saving = false
      }
    },

    async closeCase(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await careApi.close(id, payload)
        this.selectedCase = response.data?.data ?? this.selectedCase
        await this.fetchCases()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to close care case')
        throw error
      } finally {
        this.saving = false
      }
    },

    async reopenCase(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await careApi.reopen(id, payload)
        this.selectedCase = response.data?.data ?? this.selectedCase
        await this.fetchCases()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to reopen care case')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
