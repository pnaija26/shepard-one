import { defineStore } from 'pinia'
import * as globalSearchApi from '../api/globalSearch'
import { extractApiError } from '../api/client'

export const useGlobalSearchStore = defineStore('globalSearch', {
  state: () => ({
    catalog: null,
    results: null,
    syncFailures: [],
    loading: false,
    error: null,
  }),

  actions: {
    async loadCatalog() {
      const { data } = await globalSearchApi.fetchGlobalSearchCatalog()
      this.catalog = data.data
    },

    async search(query, params = {}) {
      this.loading = true
      this.error = null
      try {
        const { data } = await globalSearchApi.searchGlobalRecords(query, params)
        this.results = data.data
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to run search.')
        throw error
      } finally {
        this.loading = false
      }
    },

    async resolveRecord(recordType, recordId) {
      const { data } = await globalSearchApi.resolveGlobalSearchRecord(recordType, recordId)
      return data.data
    },

    async loadSyncFailures() {
      const { data } = await globalSearchApi.listGlobalSearchSyncFailures()
      this.syncFailures = data.data ?? []
    },
  },
})
