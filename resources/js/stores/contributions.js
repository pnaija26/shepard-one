import { defineStore } from 'pinia'
import contributionsApi from '../api/contributions'
import { extractApiError } from '../api/client'

export const useContributionsStore = defineStore('contributions', {
  state: () => ({
    items: [],
    campaigns: [],
    selected: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchItems(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await contributionsApi.list(params)
        this.items = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load contributions')
      } finally {
        this.loading = false
      }
    },

    async fetchCampaigns() {
      try {
        const response = await contributionsApi.campaigns()
        this.campaigns = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load campaigns')
      }
    },

    async select(id) {
      this.loading = true
      this.error = null
      try {
        const response = await contributionsApi.show(id)
        this.selected = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to open contribution')
        this.selected = null
        throw error
      } finally {
        this.loading = false
      }
    },

    async createManual(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await contributionsApi.createManual(payload)
        this.selected = response.data?.data ?? null
        await this.fetchItems()
        return this.selected
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create contribution')
        throw error
      } finally {
        this.saving = false
      }
    },

    async createCampaign(payload) {
      this.saving = true
      this.error = null
      try {
        await contributionsApi.createCampaign(payload)
        await this.fetchCampaigns()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create campaign')
        throw error
      } finally {
        this.saving = false
      }
    },

    async match(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await contributionsApi.match(id, payload)
        this.selected = response.data?.data ?? this.selected
        await this.fetchItems()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to match contribution')
        throw error
      } finally {
        this.saving = false
      }
    },

    async reconcile(id, payload = {}) {
      this.saving = true
      this.error = null
      try {
        const response = await contributionsApi.reconcile(id, payload)
        this.selected = response.data?.data ?? this.selected
        await this.fetchItems()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to reconcile')
        throw error
      } finally {
        this.saving = false
      }
    },

    async issueReceipt(id) {
      this.saving = true
      this.error = null
      try {
        await contributionsApi.issueReceipt(id, { deliver: true })
        await this.select(id)
        await this.fetchItems()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to issue receipt')
        throw error
      } finally {
        this.saving = false
      }
    },

    async voidReceipt(receiptId, reason) {
      this.saving = true
      this.error = null
      try {
        await contributionsApi.voidReceipt(receiptId, { reason })
        if (this.selected?.id) {
          await this.select(this.selected.id)
        }
        await this.fetchItems()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to void receipt')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
