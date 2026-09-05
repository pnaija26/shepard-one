import { defineStore } from 'pinia'
import duplicatesApi from '../api/duplicates'
import { extractApiError } from '../api/client'

export const useDuplicatesStore = defineStore('duplicates', {
  state: () => ({
    flags: [],
    comparison: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchFlags() {
      this.loading = true
      this.error = null
      try {
        const response = await duplicatesApi.listFlags({ status: 'pending_review' })
        this.flags = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load duplicate flags')
        this.flags = []
      } finally {
        this.loading = false
      }
    },

    async loadComparison(flagId) {
      this.loading = true
      this.error = null
      try {
        const response = await duplicatesApi.compare(flagId)
        this.comparison = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load duplicate comparison')
        this.comparison = null
      } finally {
        this.loading = false
      }
    },

    async dismiss(flagId) {
      this.saving = true
      this.error = null
      try {
        await duplicatesApi.dismiss(flagId)
        this.flags = this.flags.filter((flag) => flag.id !== flagId)
        this.comparison = null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to dismiss flag')
        throw error
      } finally {
        this.saving = false
      }
    },

    async merge(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await duplicatesApi.merge(payload)
        if (payload.flag_id) {
          this.flags = this.flags.filter((flag) => flag.id !== payload.flag_id)
        }
        this.comparison = null
        return response.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to merge members')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
