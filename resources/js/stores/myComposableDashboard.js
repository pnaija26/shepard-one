import { defineStore } from 'pinia'
import { fetchMyComposableDashboard } from '../api/composableDashboards'
import { extractApiError } from '../api/client'

export const useMyComposableDashboardStore = defineStore('myComposableDashboard', {
  state: () => ({
    dashboard: null,
    loading: false,
    error: null,
  }),

  getters: {
    widgets(state) {
      return state.dashboard?.widgets ?? []
    },
  },

  actions: {
    async load() {
      this.loading = true
      this.error = null
      try {
        const { data } = await fetchMyComposableDashboard()
        this.dashboard = data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load your dashboard.')
        this.dashboard = null
      } finally {
        this.loading = false
      }
    },
  },
})
