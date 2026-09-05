import { defineStore } from 'pinia'
import feedbackApi from '../api/feedback'
import { extractApiError } from '../api/client'

export const useFeedbackStore = defineStore('feedback', {
  state: () => ({
    items: [],
    loading: false,
    saving: false,
    error: null,
    message: null,
  }),

  actions: {
    async fetchFeedback(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await feedbackApi.list(params)
        this.items = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load feedback')
      } finally {
        this.loading = false
      }
    },

    async submitFeedback(payload) {
      this.saving = true
      this.error = null
      this.message = null
      try {
        const response = await feedbackApi.submit(payload)
        this.message = response.data?.message ?? 'Feedback submitted'
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to submit feedback')
        throw error
      } finally {
        this.saving = false
      }
    },

    async recordActivity(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await feedbackApi.recordActivity(id, payload)
        await this.fetchFeedback()
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to update feedback')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
