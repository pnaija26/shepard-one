import { defineStore } from 'pinia'
import * as externalAdaptersApi from '../api/externalAdapters'
import { extractApiError } from '../api/client'

export const useExternalAdaptersStore = defineStore('externalAdapters', {
  state: () => ({
    catalog: null,
    adapters: [],
    processResult: null,
    saving: false,
    error: null,
  }),

  actions: {
    async loadCatalog() {
      const { data } = await externalAdaptersApi.fetchExternalAdapterCatalog()
      this.catalog = data.data
    },

    async loadAdapters() {
      const { data } = await externalAdaptersApi.listExternalAdapters()
      this.adapters = data.data ?? []
    },

    async createAdapter(payload) {
      this.saving = true
      this.error = null
      try {
        const { data } = await externalAdaptersApi.createExternalAdapter(payload)
        await this.loadAdapters()
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create adapter.')
        throw error
      } finally {
        this.saving = false
      }
    },

    async testAdapter(id) {
      await externalAdaptersApi.testExternalAdapter(id)
      await this.loadAdapters()
    },

    async activateAdapter(id) {
      await externalAdaptersApi.activateExternalAdapter(id)
      await this.loadAdapters()
    },

    async disableAdapter(id, drainPolicy = 'drain') {
      await externalAdaptersApi.disableExternalAdapter(id, { drain_policy: drainPolicy })
      await this.loadAdapters()
    },

    async processDue() {
      const { data } = await externalAdaptersApi.processDueExternalAdapters()
      this.processResult = data.data
      return data.data
    },
  },
})
