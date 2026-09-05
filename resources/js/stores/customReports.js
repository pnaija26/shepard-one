import { defineStore } from 'pinia'
import * as customReportsApi from '../api/customReports'
import { extractApiError } from '../api/client'

export const useCustomReportsStore = defineStore('customReports', {
  state: () => ({
    catalog: null,
    reports: [],
    selected: null,
    preview: null,
    validation: null,
    runResult: null,
    loading: false,
    saving: false,
    error: null,
    failure: null,
  }),

  actions: {
    async loadCatalog() {
      const { data } = await customReportsApi.fetchCustomReportCatalog()
      this.catalog = data.data
    },

    async loadReports() {
      this.loading = true
      this.error = null
      try {
        const { data } = await customReportsApi.listCustomReports()
        this.reports = data.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load custom reports.')
      } finally {
        this.loading = false
      }
    },

    async createReport(payload) {
      this.saving = true
      this.error = null
      try {
        const { data } = await customReportsApi.createCustomReport(payload)
        this.selected = data.data
        await this.loadReports()
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create report.')
        throw error
      } finally {
        this.saving = false
      }
    },

    async saveDraft(id, payload) {
      this.saving = true
      this.error = null
      try {
        const { data } = await customReportsApi.updateCustomReportDraft(id, payload)
        this.selected = data.data
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to save draft.')
        throw error
      } finally {
        this.saving = false
      }
    },

    async validateReport(id) {
      const { data } = await customReportsApi.validateCustomReport(id)
      this.validation = data.data
      return data.data
    },

    async previewReport(id, payload = {}) {
      const { data } = await customReportsApi.previewCustomReport(id, payload)
      this.preview = data.data
      return data.data
    },

    async publishReport(id, payload = {}) {
      this.saving = true
      this.error = null
      try {
        const { data } = await customReportsApi.publishCustomReport(id, payload)
        this.selected = data.data
        await this.loadReports()
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to publish report.')
        throw error
      } finally {
        this.saving = false
      }
    },

    async runReport(id, params = {}) {
      this.loading = true
      this.error = null
      this.failure = null
      this.runResult = null
      try {
        const { data } = await customReportsApi.runCustomReport(id, params)
        this.runResult = data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to run report.')
        this.failure = error.response?.data ?? null
      } finally {
        this.loading = false
      }
    },
  },
})
