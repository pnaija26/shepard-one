import { defineStore } from 'pinia'
import followUpsApi from '../api/followUps'
import { extractApiError } from '../api/client'

export const useFollowUpStore = defineStore('followUps', {
  state: () => ({
    followUps: [],
    selected: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchFollowUps() {
      this.loading = true
      this.error = null
      try {
        const response = await followUpsApi.listFollowUps()
        this.followUps = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load follow-ups')
      } finally {
        this.loading = false
      }
    },

    async createFollowUp(payload) {
      this.saving = true
      this.error = null
      try {
        await followUpsApi.createFollowUp(payload)
        await this.fetchFollowUps()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create follow-up')
        throw error
      } finally {
        this.saving = false
      }
    },

    async recordActivity(id, payload) {
      this.saving = true
      try {
        const response = await followUpsApi.recordActivity(id, payload)
        await this.fetchFollowUps()
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to record activity')
        throw error
      } finally {
        this.saving = false
      }
    },

    async processEscalations() {
      this.saving = true
      try {
        const response = await followUpsApi.processEscalations()
        await this.fetchFollowUps()
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to process escalations')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
