import { defineStore } from 'pinia'
import * as apiPlatformApi from '../api/apiPlatform'
import { extractApiError } from '../api/client'

export const useApiPlatformStore = defineStore('apiPlatform', {
  state: () => ({
    catalog: null,
    contract: null,
    validation: null,
    clients: [],
    latestSecret: null,
    saving: false,
    loading: false,
    error: null,
  }),

  actions: {
    async loadCatalog() {
      const { data } = await apiPlatformApi.fetchApiPlatformCatalog()
      this.catalog = data.data
    },

    async loadContract() {
      this.loading = true
      this.error = null
      try {
        const { data } = await apiPlatformApi.fetchApiPlatformContract()
        this.contract = data.data
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load API contract.')
        throw error
      } finally {
        this.loading = false
      }
    },

    async validateContract() {
      const { data } = await apiPlatformApi.validateApiPlatformContract()
      this.validation = data.data
      return data.data
    },

    async loadClients() {
      const { data } = await apiPlatformApi.listApiPlatformClients()
      this.clients = data.data ?? []
    },

    async createClient(payload) {
      this.saving = true
      this.error = null
      try {
        const { data } = await apiPlatformApi.createApiPlatformClient(payload)
        this.latestSecret = data.data.client_secret
        await this.loadClients()
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create API client.')
        throw error
      } finally {
        this.saving = false
      }
    },

    async revokeClient(id) {
      await apiPlatformApi.revokeApiPlatformClient(id)
      await this.loadClients()
    },
  },
})
