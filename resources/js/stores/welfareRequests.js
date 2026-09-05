import { defineStore } from 'pinia'
import welfareApi from '../api/welfareRequests'
import { extractApiError } from '../api/client'

export const useWelfareRequestsStore = defineStore('welfareRequests', {
  state: () => ({
    requests: [],
    myRequests: [],
    selectedRequest: null,
    report: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchRequests(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await welfareApi.list(params)
        this.requests = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load welfare requests')
      } finally {
        this.loading = false
      }
    },

    async fetchMyRequests() {
      this.loading = true
      this.error = null
      try {
        const response = await welfareApi.myRequests()
        this.myRequests = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load your welfare requests')
      } finally {
        this.loading = false
      }
    },

    async createRequest(payload, self = false) {
      this.saving = true
      this.error = null
      try {
        const response = self
          ? await welfareApi.createMine(payload)
          : await welfareApi.create(payload)
        await Promise.all([this.fetchRequests(), this.fetchMyRequests()])
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to save welfare request draft')
        throw error
      } finally {
        this.saving = false
      }
    },

    async selectRequest(id) {
      this.loading = true
      try {
        const response = await welfareApi.show(id)
        this.selectedRequest = response.data?.data ?? null
      } finally {
        this.loading = false
      }
    },

    async submitRequest(id) {
      this.saving = true
      try {
        const response = await welfareApi.submit(id)
        this.selectedRequest = response.data?.data ?? this.selectedRequest
        await Promise.all([this.fetchRequests(), this.fetchMyRequests()])
      } finally {
        this.saving = false
      }
    },

    async assessRequest(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await welfareApi.assess(id, payload)
        this.selectedRequest = response.data?.data ?? this.selectedRequest
        await this.fetchRequests()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to record assessment')
        throw error
      } finally {
        this.saving = false
      }
    },

    async recordCondition(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await welfareApi.recordCondition(id, payload)
        this.selectedRequest = response.data?.data ?? this.selectedRequest
        await this.fetchRequests()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to record case condition')
        throw error
      } finally {
        this.saving = false
      }
    },

    async decide(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await welfareApi.decide(id, payload)
        this.selectedRequest = response.data?.data ?? this.selectedRequest
        await this.fetchRequests()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to record approval decision')
        throw error
      } finally {
        this.saving = false
      }
    },

    async recordDelivery(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await welfareApi.recordDelivery(id, payload)
        this.selectedRequest = response.data?.case ?? this.selectedRequest
        await this.fetchRequests()
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to record assistance delivery')
        throw error
      } finally {
        this.saving = false
      }
    },

    async confirmDelivery(deliveryId, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await welfareApi.confirmDelivery(deliveryId, payload)
        this.selectedRequest = response.data?.data?.case ?? this.selectedRequest
        await this.fetchRequests()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to record confirmation')
        throw error
      } finally {
        this.saving = false
      }
    },

    async recordFollowUp(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await welfareApi.recordFollowUp(id, payload)
        this.selectedRequest = response.data?.case ?? this.selectedRequest
        await this.fetchRequests()
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to record follow-up')
        throw error
      } finally {
        this.saving = false
      }
    },

    async closeRequest(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await welfareApi.closeRequest(id, payload)
        this.selectedRequest = response.data?.case ?? this.selectedRequest
        await this.fetchRequests()
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to close welfare case')
        throw error
      } finally {
        this.saving = false
      }
    },

    async fetchReport(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await welfareApi.report(params)
        this.report = response.data?.data ?? null
        return this.report
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load welfare report')
        throw error
      } finally {
        this.loading = false
      }
    },
  },
})
