import { defineStore } from 'pinia'
import volunteersApi from '../api/volunteers'
import { extractApiError } from '../api/client'

export const useVolunteersStore = defineStore('volunteers', {
  state: () => ({
    profiles: [],
    selectedProfile: null,
    alerts: null,
    myProfile: null,
    recommendations: null,
    recommendationDuty: null,
    loading: false,
    saving: false,
    error: null,
    message: null,
  }),

  actions: {
    async fetchProfiles(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await volunteersApi.list(params)
        this.profiles = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load volunteer profiles')
      } finally {
        this.loading = false
      }
    },

    async fetchAlerts() {
      try {
        const response = await volunteersApi.alerts()
        this.alerts = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load volunteer alerts')
      }
    },

    async createProfile(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await volunteersApi.create(payload)
        await this.fetchProfiles()
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create volunteer profile')
        throw error
      } finally {
        this.saving = false
      }
    },

    async selectProfile(id) {
      this.loading = true
      this.error = null
      try {
        const response = await volunteersApi.show(id)
        this.selectedProfile = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load volunteer profile')
      } finally {
        this.loading = false
      }
    },

    async verifyChange(changeId, approve, reason = '') {
      this.saving = true
      try {
        const response = await volunteersApi.verifyChange(changeId, { approve, reason })
        this.selectedProfile = response.data?.data ?? this.selectedProfile
        await this.fetchProfiles()
      } finally {
        this.saving = false
      }
    },

    async fetchMyProfile() {
      this.loading = true
      this.error = null
      try {
        const response = await volunteersApi.myProfile()
        this.myProfile = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load your volunteer profile')
      } finally {
        this.loading = false
      }
    },

    async updateMyProfile(payload) {
      this.saving = true
      this.error = null
      this.message = null
      try {
        const response = await volunteersApi.updateMyProfile(payload)
        this.myProfile = response.data?.data ?? null
        this.message = 'Volunteer profile updated.'
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to update volunteer profile')
        throw error
      } finally {
        this.saving = false
      }
    },

    async fetchRecommendations(teamId, payload) {
      this.loading = true
      this.error = null
      try {
        const response = await volunteersApi.recommendForTeam(teamId, payload)
        this.recommendations = response.data?.data?.recommendations ?? []
        this.recommendationDuty = response.data?.data?.duty ?? null
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load volunteer recommendations')
        throw error
      } finally {
        this.loading = false
      }
    },

    async confirmRecommendation(teamId, payload) {
      this.saving = true
      this.error = null
      this.message = null
      try {
        const response = await volunteersApi.confirmRecommendation(teamId, payload)
        this.message = response.data?.message ?? 'Volunteer assignment confirmed.'
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to confirm volunteer assignment')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
