import { defineStore } from 'pinia'
import membershipCardApi from '../api/membershipCard'
import { extractApiError } from '../api/client'

export const useMembershipCardStore = defineStore('membershipCard', {
  state: () => ({
    card: null,
    purposes: [],
    verification: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchCard() {
      this.loading = true
      this.error = null
      try {
        const response = await membershipCardApi.getMyCard()
        this.card = response.data?.data ?? null
        return this.card
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load membership card')
        this.card = null
        throw error
      } finally {
        this.loading = false
      }
    },

    async fetchPurposes() {
      try {
        const response = await membershipCardApi.listPurposes()
        this.purposes = response.data?.data ?? []
        return this.purposes
      } catch {
        this.purposes = []
        return []
      }
    },

    async verify(token, purpose) {
      this.saving = true
      this.error = null
      this.verification = null
      try {
        const response = await membershipCardApi.verify(token, purpose)
        this.verification = response.data?.data ?? null
        return this.verification
      } catch (error) {
        this.error = extractApiError(error, 'Card verification failed')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
