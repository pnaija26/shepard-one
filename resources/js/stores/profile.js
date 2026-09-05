import { defineStore } from 'pinia'
import api from '@/api/profile'
import { extractApiError } from '@/api/client'

export const useProfileStore = defineStore('profile', {
  state: () => ({
    profile: null,
    pendingReviews: [],
    loading: false,
    saving: false,
    error: null,
    lastChanges: null,
  }),

  actions: {
    async fetchProfile() {
      this.loading = true
      this.error = null

      try {
        const response = await api.getProfile()
        this.profile = response.data?.data ?? null
        return this.profile
      } catch (error) {
        this.error = extractApiError(error, 'Failed to load your profile')
        throw error
      } finally {
        this.loading = false
      }
    },

    async updateProfile(payload) {
      this.saving = true
      this.error = null

      try {
        const response = await api.updateProfile(payload)
        this.profile = response.data?.data ?? null
        this.lastChanges = response.data?.changes ?? null
        return response.data
      } catch (error) {
        this.error = extractApiError(error, 'Failed to update profile')
        throw error
      } finally {
        this.saving = false
      }
    },

    async fetchPendingReviews() {
      this.loading = true
      this.error = null

      try {
        const response = await api.listPendingChanges()
        this.pendingReviews = response.data?.data ?? []
        return this.pendingReviews
      } catch (error) {
        this.error = extractApiError(error, 'Failed to load pending profile changes')
        throw error
      } finally {
        this.loading = false
      }
    },

    async approveChange(id) {
      await api.approveChange(id)
      await this.fetchPendingReviews()
    },

    async rejectChange(id, reason = null) {
      await api.rejectChange(id, reason)
      await this.fetchPendingReviews()
    },
  },
})
