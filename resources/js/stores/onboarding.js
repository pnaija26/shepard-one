import { defineStore } from 'pinia'
import onboardingApi from '../api/onboarding'
import { extractApiError } from '../api/client'

export const useOnboardingStore = defineStore('onboarding', {
  state: () => ({
    journeys: [],
    enrollments: [],
    selectedEnrollment: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchJourneys() {
      this.loading = true
      this.error = null
      try {
        const response = await onboardingApi.listJourneys()
        this.journeys = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load journeys')
      } finally {
        this.loading = false
      }
    },

    async fetchEnrollments() {
      this.loading = true
      this.error = null
      try {
        const response = await onboardingApi.listEnrollments()
        this.enrollments = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load enrollments')
      } finally {
        this.loading = false
      }
    },

    async createAndPublishJourney(payload) {
      this.saving = true
      this.error = null
      try {
        const created = await onboardingApi.createJourney(payload)
        const journeyId = created.data?.data?.id
        await onboardingApi.publishJourney(journeyId, { steps: payload.steps })
        await this.fetchJourneys()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to save journey')
        throw error
      } finally {
        this.saving = false
      }
    },

    async processDue() {
      this.saving = true
      try {
        const response = await onboardingApi.processDue()
        await this.fetchEnrollments()
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to process due steps')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
