import { defineStore } from 'pinia'
import communicationsApi from '../api/communications'
import { extractApiError } from '../api/client'

export const useCommunicationsStore = defineStore('communications', {
  state: () => ({
    items: [],
    selected: null,
    processResult: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchItems(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await communicationsApi.list(params)
        this.items = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load communications')
      } finally {
        this.loading = false
      }
    },

    async create(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await communicationsApi.create(payload)
        this.selected = response.data?.data ?? null
        await this.fetchItems()
        return this.selected
      } catch (error) {
        this.error = extractApiError(error, 'Unable to send communication')
        throw error
      } finally {
        this.saving = false
      }
    },

    async select(id) {
      this.loading = true
      this.error = null
      try {
        const response = await communicationsApi.show(id)
        this.selected = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to open communication')
        this.selected = null
        throw error
      } finally {
        this.loading = false
      }
    },

    async cancel(id) {
      this.saving = true
      this.error = null
      try {
        const response = await communicationsApi.cancel(id)
        this.selected = response.data?.data ?? this.selected
        await this.fetchItems()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to cancel communication')
        throw error
      } finally {
        this.saving = false
      }
    },

    async processDue() {
      this.saving = true
      this.error = null
      try {
        const response = await communicationsApi.processDue()
        this.processResult = response.data?.data ?? null
        await this.fetchItems()
        return this.processResult
      } catch (error) {
        this.error = extractApiError(error, 'Unable to process due communications')
        throw error
      } finally {
        this.saving = false
      }
    },

    async processRetries() {
      this.saving = true
      this.error = null
      try {
        const response = await communicationsApi.processRetries()
        this.processResult = response.data?.data ?? null
        await this.fetchItems()
        return this.processResult
      } catch (error) {
        this.error = extractApiError(error, 'Unable to process retries')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
