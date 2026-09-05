import { defineStore } from 'pinia'
import visitorsApi from '../api/visitors'
import { extractApiError } from '../api/client'

export const useVisitorsStore = defineStore('visitors', {
  state: () => ({
    visitors: [],
    selectedVisitor: null,
    loading: false,
    saving: false,
    error: null,
    duplicateWarning: null,
  }),

  actions: {
    async fetchVisitors(search = '') {
      this.loading = true
      this.error = null
      try {
        const response = await visitorsApi.listVisitors(search ? { search } : {})
        this.visitors = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load visitors')
        this.visitors = []
      } finally {
        this.loading = false
      }
    },

    async fetchVisitor(id) {
      this.loading = true
      this.error = null
      try {
        const response = await visitorsApi.getVisitor(id)
        this.selectedVisitor = response.data?.data ?? null
        return this.selectedVisitor
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load visitor')
        throw error
      } finally {
        this.loading = false
      }
    },

    async captureVisitor(payload) {
      this.saving = true
      this.error = null
      this.duplicateWarning = null
      try {
        const response = await visitorsApi.captureVisitor(payload)
        await this.fetchVisitors()
        return response.data
      } catch (error) {
        if (error.response?.status === 422 && error.response?.data?.duplicate_review_required) {
          this.duplicateWarning = error.response.data
          return { duplicate: error.response.data }
        }
        this.error = extractApiError(error, 'Unable to capture visitor')
        throw error
      } finally {
        this.saving = false
      }
    },

    async recordVisit(visitorId, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await visitorsApi.recordVisit(visitorId, payload)
        this.selectedVisitor = response.data?.data ?? null
        await this.fetchVisitors()
        return response.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to record visit')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
