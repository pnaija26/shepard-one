import { defineStore } from 'pinia'
import * as reportExportsApi from '../api/reportExports'
import { extractApiError } from '../api/client'

export const useReportExportsStore = defineStore('reportExports', {
  state: () => ({
    catalog: null,
    lastExport: null,
    status: null,
    loading: false,
    error: null,
  }),

  actions: {
    async loadCatalog() {
      const { data } = await reportExportsApi.fetchReportExportCatalog()
      this.catalog = data.data
    },

    async requestExport(payload) {
      this.loading = true
      this.error = null
      try {
        const { data } = await reportExportsApi.requestReportExport(payload)
        this.lastExport = data.data

        if (data.data?.async) {
          await this.pollStatus(data.data.reference)
        }

        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to request export.')
        throw error
      } finally {
        this.loading = false
      }
    },

    async pollStatus(reference, attempts = 5) {
      for (let i = 0; i < attempts; i += 1) {
        const { data } = await reportExportsApi.fetchReportExportStatus(reference)
        this.status = data.data
        if (data.data?.status === 'completed' || data.data?.status === 'failed') {
          return data.data
        }
        await new Promise((resolve) => setTimeout(resolve, 300))
      }

      return this.status
    },

    async downloadExport(reference, token, filename = 'report-export.csv') {
      const { data } = await reportExportsApi.downloadReportExport(reference, token)
      const url = window.URL.createObjectURL(new Blob([data]))
      const link = document.createElement('a')
      link.href = url
      link.setAttribute('download', filename)
      document.body.appendChild(link)
      link.click()
      link.remove()
      window.URL.revokeObjectURL(url)
    },
  },
})
