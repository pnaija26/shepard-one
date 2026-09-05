import { defineStore } from 'pinia'
import * as composableDashboardsApi from '../api/composableDashboards'
import { extractApiError } from '../api/client'

export const useComposableDashboardsStore = defineStore('composableDashboards', {
  state: () => ({
    catalog: null,
    dashboards: [],
    selected: null,
    preview: null,
    validation: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async loadCatalog() {
      const { data } = await composableDashboardsApi.fetchDashboardCatalog()
      this.catalog = data.data
    },

    async loadDashboards() {
      this.loading = true
      this.error = null
      try {
        const { data } = await composableDashboardsApi.listComposableDashboards()
        this.dashboards = data.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load dashboards.')
      } finally {
        this.loading = false
      }
    },

    async createDashboard(payload) {
      this.saving = true
      this.error = null
      try {
        const { data } = await composableDashboardsApi.createComposableDashboard(payload)
        this.selected = data.data
        await this.loadDashboards()
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create dashboard.')
        throw error
      } finally {
        this.saving = false
      }
    },

    async saveDraft(id, payload) {
      this.saving = true
      this.error = null
      try {
        const { data } = await composableDashboardsApi.updateComposableDashboardDraft(id, payload)
        this.selected = data.data
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to save draft.')
        throw error
      } finally {
        this.saving = false
      }
    },

    async validateDashboard(id) {
      const { data } = await composableDashboardsApi.validateComposableDashboard(id)
      this.validation = data.data
      return data.data
    },

    async previewDashboard(id, payload = {}) {
      const { data } = await composableDashboardsApi.previewComposableDashboard(id, payload)
      this.preview = data.data
      return data.data
    },

    async publishDashboard(id) {
      this.saving = true
      this.error = null
      try {
        const { data } = await composableDashboardsApi.publishComposableDashboard(id)
        this.selected = data.data
        await this.loadDashboards()
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to publish dashboard.')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
