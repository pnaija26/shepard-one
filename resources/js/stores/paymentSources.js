import { defineStore } from 'pinia'
import paymentSourcesApi from '../api/paymentSources'
import { extractApiError } from '../api/client'

export const usePaymentSourcesStore = defineStore('paymentSources', {
  state: () => ({
    sources: [],
    contributions: [],
    selected: null,
    testResult: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchSources() {
      this.loading = true
      this.error = null
      try {
        const response = await paymentSourcesApi.list()
        this.sources = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load payment sources')
      } finally {
        this.loading = false
      }
    },

    async fetchContributions(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await paymentSourcesApi.contributions(params)
        this.contributions = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load contributions')
      } finally {
        this.loading = false
      }
    },

    async create(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await paymentSourcesApi.create(payload)
        this.selected = response.data?.data ?? null
        await this.fetchSources()
        return this.selected
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create payment source')
        throw error
      } finally {
        this.saving = false
      }
    },

    async select(id) {
      this.loading = true
      this.error = null
      try {
        const response = await paymentSourcesApi.show(id)
        this.selected = response.data?.data ?? null
        this.testResult = null
        await this.fetchContributions({ payment_source_id: id })
      } catch (error) {
        this.error = extractApiError(error, 'Unable to open payment source')
        this.selected = null
        throw error
      } finally {
        this.loading = false
      }
    },

    async update(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await paymentSourcesApi.update(id, payload)
        this.selected = response.data?.data ?? this.selected
        await this.fetchSources()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to update payment source')
        throw error
      } finally {
        this.saving = false
      }
    },

    async test(id) {
      this.saving = true
      this.error = null
      try {
        const response = await paymentSourcesApi.test(id)
        this.testResult = response.data?.data ?? null
        await this.select(id)
        return this.testResult
      } catch (error) {
        this.error = extractApiError(error, 'Connection test failed')
        this.testResult = error.response?.data?.details
          ? { passed: false, details: error.response.data.details }
          : null
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
