import { defineStore } from 'pinia'
import directoryApi from '../api/directory'
import { extractApiError } from '../api/client'

export const useDirectoryStore = defineStore('directory', {
  state: () => ({
    settings: null,
    results: [],
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchSettings() {
      this.loading = true
      this.error = null
      try {
        const response = await directoryApi.getSettings()
        this.settings = response.data?.data ?? null
        return this.settings
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load directory settings')
        throw error
      } finally {
        this.loading = false
      }
    },

    async updateSettings(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await directoryApi.updateSettings(payload)
        this.settings = response.data?.data ?? null
        return this.settings
      } catch (error) {
        this.error = extractApiError(error, 'Unable to save directory settings')
        throw error
      } finally {
        this.saving = false
      }
    },

    async search(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await directoryApi.search(params)
        this.results = response.data?.data ?? []
        return this.results
      } catch (error) {
        this.error = extractApiError(error, 'Unable to search directory')
        this.results = []
        throw error
      } finally {
        this.loading = false
      }
    },
  },
})
