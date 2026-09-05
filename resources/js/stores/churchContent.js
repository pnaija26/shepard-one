import { defineStore } from 'pinia'
import churchContentApi from '../api/churchContent'
import { extractApiError } from '../api/client'

export const useChurchContentStore = defineStore('churchContent', {
  state: () => ({
    items: [],
    feed: [],
    searchResults: [],
    selected: null,
    validation: null,
    preview: null,
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
        const response = await churchContentApi.list()
        this.items = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load content')
      } finally {
        this.loading = false
      }
    },

    async fetchFeed(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await churchContentApi.feed(params)
        this.feed = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load feed')
      } finally {
        this.loading = false
      }
    },

    async create(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await churchContentApi.create(payload)
        this.selected = response.data?.data ?? null
        await this.fetchItems()
        return this.selected
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create content')
        throw error
      } finally {
        this.saving = false
      }
    },

    async select(id) {
      this.loading = true
      this.error = null
      try {
        const response = await churchContentApi.show(id)
        this.selected = response.data?.data ?? null
        this.validation = null
        this.preview = null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to open content')
        this.selected = null
        throw error
      } finally {
        this.loading = false
      }
    },

    async validate(id) {
      this.saving = true
      this.error = null
      try {
        const response = await churchContentApi.validate(id)
        this.validation = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to validate content')
        throw error
      } finally {
        this.saving = false
      }
    },

    async preview(id, payload = {}) {
      this.saving = true
      this.error = null
      try {
        const response = await churchContentApi.preview(id, payload)
        this.preview = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to preview content')
        throw error
      } finally {
        this.saving = false
      }
    },

    async submit(id) {
      this.saving = true
      this.error = null
      try {
        const response = await churchContentApi.submit(id)
        this.selected = response.data?.data ?? this.selected
        await this.fetchItems()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to submit content')
        throw error
      } finally {
        this.saving = false
      }
    },

    async approve(id, payload = {}) {
      this.saving = true
      this.error = null
      try {
        const response = await churchContentApi.approve(id, payload)
        this.selected = response.data?.data ?? this.selected
        await this.fetchItems()
        await this.fetchFeed()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to approve content')
        throw error
      } finally {
        this.saving = false
      }
    },

    async withdraw(id, reason = 'Withdrawn') {
      this.saving = true
      this.error = null
      try {
        const response = await churchContentApi.withdraw(id, { reason })
        this.selected = response.data?.data ?? this.selected
        await this.fetchItems()
        await this.fetchFeed()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to withdraw content')
        throw error
      } finally {
        this.saving = false
      }
    },

    async processWindows() {
      this.saving = true
      this.error = null
      try {
        const response = await churchContentApi.processWindows()
        this.processResult = response.data?.data ?? null
        await this.fetchItems()
        await this.fetchFeed()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to process publish windows')
        throw error
      } finally {
        this.saving = false
      }
    },

    async search(q) {
      this.loading = true
      this.error = null
      try {
        const response = await churchContentApi.search({ q, device: 'web' })
        this.searchResults = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to search content')
        throw error
      } finally {
        this.loading = false
      }
    },
  },
})
