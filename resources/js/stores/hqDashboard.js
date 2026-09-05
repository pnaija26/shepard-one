import { defineStore } from 'pinia'
import * as hqDashboardApi from '../api/hqDashboard'
import { extractApiError } from '../api/client'

export const useHqDashboardStore = defineStore('hqDashboard', {
  state: () => ({
    dashboard: null,
    drillDown: null,
    loading: false,
    drillLoading: false,
    error: null,
    periodFrom: '',
    periodTo: '',
    branchFilter: '',
  }),

  getters: {
    metrics(state) {
      return state.dashboard?.metrics ?? {}
    },
    branchComparison(state) {
      return state.dashboard?.branch_comparison ?? null
    },
    definitions(state) {
      return state.dashboard?.definitions ?? {}
    },
    period(state) {
      return state.dashboard?.period ?? null
    },
  },

  actions: {
    async loadDashboard() {
      this.loading = true
      this.error = null
      this.drillDown = null

      const params = {}
      if (this.periodFrom) params.period_from = this.periodFrom
      if (this.periodTo) params.period_to = this.periodTo
      if (this.branchFilter) params.branch_id = this.branchFilter

      try {
        const { data } = await hqDashboardApi.fetchHqDashboard(params)
        this.dashboard = data.data

        if (this.dashboard?.period) {
          this.periodFrom = this.dashboard.period.from ?? this.periodFrom
          this.periodTo = this.dashboard.period.to ?? this.periodTo
        }
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load HQ dashboard.')
        this.dashboard = null
      } finally {
        this.loading = false
      }
    },

    async openDrillDown(metric, params = {}) {
      this.drillLoading = true
      this.error = null

      const query = { ...params }
      if (this.periodFrom) query.period_from = this.periodFrom
      if (this.periodTo) query.period_to = this.periodTo
      if (this.branchFilter) query.branch_id = this.branchFilter

      try {
        const { data } = await hqDashboardApi.fetchHqDashboardDrillDown(metric, query)
        this.drillDown = data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load drill-down records.')
        this.drillDown = null
      } finally {
        this.drillLoading = false
      }
    },
  },
})
