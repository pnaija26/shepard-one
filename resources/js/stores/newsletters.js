import { defineStore } from 'pinia'
import newslettersApi from '../api/newsletters'
import { extractApiError } from '../api/client'

export const useNewslettersStore = defineStore('newsletters', {
  state: () => ({
    items: [],
    selected: null,
    validation: null,
    preview: null,
    analytics: null,
    processResult: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchItems() {
      this.loading = true
      this.error = null
      try {
        const response = await newslettersApi.list()
        this.items = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load newsletters')
      } finally {
        this.loading = false
      }
    },

    async create(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await newslettersApi.create(payload)
        this.selected = response.data?.data ?? null
        await this.fetchItems()
        return this.selected
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create newsletter')
        throw error
      } finally {
        this.saving = false
      }
    },

    async select(id) {
      this.loading = true
      this.error = null
      try {
        const response = await newslettersApi.show(id)
        this.selected = response.data?.data ?? null
        this.validation = null
        this.preview = null
        this.analytics = null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to open newsletter')
        this.selected = null
        throw error
      } finally {
        this.loading = false
      }
    },

    async updateDraft(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await newslettersApi.updateDraft(id, payload)
        this.selected = response.data?.data ?? this.selected
        await this.fetchItems()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to update draft')
        throw error
      } finally {
        this.saving = false
      }
    },

    async validate(id) {
      this.saving = true
      this.error = null
      try {
        const response = await newslettersApi.validate(id)
        this.validation = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to validate newsletter')
        throw error
      } finally {
        this.saving = false
      }
    },

    async preview(id, payload = {}) {
      this.saving = true
      this.error = null
      try {
        const response = await newslettersApi.preview(id, payload)
        this.preview = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to preview newsletter')
        throw error
      } finally {
        this.saving = false
      }
    },

    async sendTest(id, payload) {
      this.saving = true
      this.error = null
      try {
        return (await newslettersApi.sendTest(id, payload)).data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to send test')
        throw error
      } finally {
        this.saving = false
      }
    },

    async submit(id) {
      this.saving = true
      this.error = null
      try {
        const response = await newslettersApi.submit(id)
        this.selected = response.data?.data ?? this.selected
        await this.fetchItems()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to submit newsletter')
        throw error
      } finally {
        this.saving = false
      }
    },

    async approve(id, payload = {}) {
      this.saving = true
      this.error = null
      try {
        const response = await newslettersApi.approve(id, payload)
        this.selected = response.data?.data ?? this.selected
        await this.fetchItems()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to approve newsletter')
        throw error
      } finally {
        this.saving = false
      }
    },

    async processDue(params = {}) {
      this.saving = true
      this.error = null
      try {
        const response = await newslettersApi.processDue(params)
        this.processResult = response.data?.data ?? null
        await this.fetchItems()
        return this.processResult
      } catch (error) {
        this.error = extractApiError(error, 'Unable to process due newsletters')
        throw error
      } finally {
        this.saving = false
      }
    },

    async fetchAnalytics(id) {
      this.loading = true
      this.error = null
      try {
        const response = await newslettersApi.analytics(id)
        this.analytics = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load analytics')
        throw error
      } finally {
        this.loading = false
      }
    },
  },
})
