import { defineStore } from 'pinia'
import trainingApi from '../api/trainingOfferings'
import trainingProgressApi from '../api/trainingProgress'
import { extractApiError } from '../api/client'

export const useTrainingOfferingsStore = defineStore('trainingOfferings', {
  state: () => ({
    offerings: [],
    selectedOffering: null,
    enrolments: [],
    selectedProgress: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchOfferings(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await trainingApi.list(params)
        this.offerings = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load training offerings')
      } finally {
        this.loading = false
      }
    },

    async createOffering(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await trainingApi.create(payload)
        await this.fetchOfferings()
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create training offering')
        throw error
      } finally {
        this.saving = false
      }
    },

    async selectOffering(id) {
      this.loading = true
      this.error = null
      try {
        const response = await trainingApi.show(id)
        this.selectedOffering = response.data?.data ?? null
        await this.fetchEnrolments(id)
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load training offering')
      } finally {
        this.loading = false
      }
    },

    async publishOffering(id) {
      this.saving = true
      try {
        const response = await trainingApi.publish(id)
        this.selectedOffering = response.data?.data ?? this.selectedOffering
        await this.fetchOfferings()
      } finally {
        this.saving = false
      }
    },

    async fetchEnrolments(offeringId) {
      try {
        const response = await trainingApi.listEnrolments(offeringId)
        this.enrolments = response.data?.data ?? []
      } catch {
        this.enrolments = []
      }
    },

    async enrolMember(offeringId, payload) {
      this.saving = true
      try {
        await trainingApi.enrol(offeringId, payload)
        await this.fetchEnrolments(offeringId)
      } finally {
        this.saving = false
      }
    },

    async fetchProgress(enrolmentId) {
      this.loading = true
      try {
        const response = await trainingProgressApi.show(enrolmentId)
        this.selectedProgress = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load learner progress')
      } finally {
        this.loading = false
      }
    },

    async recordProgressAttendance(enrolmentId, payload) {
      this.saving = true
      try {
        const response = await trainingProgressApi.recordAttendance(enrolmentId, payload)
        this.selectedProgress = response.data?.data ?? this.selectedProgress
      } finally {
        this.saving = false
      }
    },

    async confirmCompletion(enrolmentId) {
      this.saving = true
      try {
        await trainingProgressApi.confirmCompletion(enrolmentId)
        await this.fetchProgress(enrolmentId)
      } finally {
        this.saving = false
      }
    },
  },
})
