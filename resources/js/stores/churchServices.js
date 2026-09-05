import { defineStore } from 'pinia'
import servicesApi from '../api/services'
import { extractApiError } from '../api/client'

export const useChurchServiceStore = defineStore('churchServices', {
  state: () => ({
    services: [],
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchServices() {
      this.loading = true
      this.error = null
      try {
        const response = await servicesApi.listServices()
        this.services = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load services')
      } finally {
        this.loading = false
      }
    },

    async createAndPublish(payload) {
      this.saving = true
      this.error = null
      try {
        const created = await servicesApi.createService(payload)
        const serviceId = created.data?.data?.id
        await servicesApi.publishService(serviceId)
        await this.fetchServices()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to save service')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
