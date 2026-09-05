import { defineStore } from 'pinia'
import prayerApi from '../api/prayerRequests'
import { extractApiError } from '../api/client'

export const usePrayerRequestsStore = defineStore('prayerRequests', {
  state: () => ({
    requests: [],
    myRequests: [],
    selectedRequest: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchRequests(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await prayerApi.list(params)
        this.requests = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load prayer requests')
      } finally {
        this.loading = false
      }
    },

    async fetchMyRequests() {
      this.loading = true
      this.error = null
      try {
        const response = await prayerApi.myRequests()
        this.myRequests = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load your prayer requests')
      } finally {
        this.loading = false
      }
    },

    async createRequest(payload, self = true) {
      this.saving = true
      this.error = null
      try {
        const response = self
          ? await prayerApi.createMine(payload)
          : await prayerApi.create(payload)
        await Promise.all([this.fetchRequests(), this.fetchMyRequests()])
        this.selectedRequest = response.data?.data ?? null
        return this.selectedRequest
      } catch (error) {
        this.error = extractApiError(error, 'Unable to submit prayer request')
        throw error
      } finally {
        this.saving = false
      }
    },

    async selectRequest(id) {
      this.loading = true
      this.error = null
      try {
        const response = await prayerApi.show(id)
        this.selectedRequest = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to open prayer request')
        this.selectedRequest = null
        throw error
      } finally {
        this.loading = false
      }
    },

    async updateConfidentiality(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await prayerApi.updateConfidentiality(id, payload)
        this.selectedRequest = response.data?.data ?? this.selectedRequest
        await Promise.all([this.fetchRequests(), this.fetchMyRequests()])
      } catch (error) {
        this.error = extractApiError(error, 'Unable to update confidentiality')
        throw error
      } finally {
        this.saving = false
      }
    },

    async withdraw(id, payload = {}) {
      this.saving = true
      this.error = null
      try {
        const response = await prayerApi.withdraw(id, payload)
        this.selectedRequest = response.data?.data ?? this.selectedRequest
        await Promise.all([this.fetchRequests(), this.fetchMyRequests()])
      } catch (error) {
        this.error = extractApiError(error, 'Unable to withdraw prayer request')
        throw error
      } finally {
        this.saving = false
      }
    },

    async runProcess(action, id, payload = {}) {
      this.saving = true
      this.error = null
      try {
        const response = await prayerApi[action](id, payload)
        this.selectedRequest = response.data?.data ?? this.selectedRequest
        await Promise.all([this.fetchRequests(), this.fetchMyRequests()])
        return this.selectedRequest
      } catch (error) {
        this.error = extractApiError(error, 'Unable to process prayer request')
        throw error
      } finally {
        this.saving = false
      }
    },

    assign(id, payload) {
      return this.runProcess('assign', id, payload)
    },

    acknowledge(id, payload = {}) {
      return this.runProcess('acknowledge', id, payload)
    },

    recordUpdate(id, payload) {
      return this.runProcess('recordUpdate', id, payload)
    },

    escalate(id, payload) {
      return this.runProcess('escalate', id, payload)
    },

    markAnswered(id, payload = {}) {
      return this.runProcess('markAnswered', id, payload)
    },

    close(id, payload = {}) {
      return this.runProcess('close', id, payload)
    },

    publishToGroup(id, payload = {}) {
      return this.runProcess('publishToGroup', id, payload)
    },
  },
})
