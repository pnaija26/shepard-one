import { defineStore } from 'pinia'
import * as standardReportsApi from '../api/standardReports'
import { extractApiError } from '../api/client'

export const useStandardReportsStore = defineStore('standardReports', {
  state: () => ({
    catalog: null,
    report: null,
    loading: false,
    error: null,
    failure: null,
  }),

  actions: {
    async loadCatalog() {
      this.error = null
      try {
        const { data } = await standardReportsApi.fetchStandardReportCatalog()
        this.catalog = data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load report catalog.')
      }
    },

    async runReport(reportKey, params = {}) {
      this.loading = true
      this.error = null
      this.failure = null
      this.report = null
      try {
        const { data } = await standardReportsApi.runStandardReport(reportKey, params)
        this.report = data.data
      } catch (error) {
        const message = extractApiError(error, 'Unable to run report.')
        this.error = message
        this.failure = error.response?.data ?? null
      } finally {
        this.loading = false
      }
    },
  },
})
