import { defineStore } from 'pinia'
import * as branchDashboardApi from '../api/branchDashboard'
import { extractApiError } from '../api/client'

export const useBranchDashboardStore = defineStore('branchDashboard', {
  state: () => ({
    branches: [],
    dashboard: null,
    drillDown: null,
    selectedBranchId: null,
    loading: false,
    drillLoading: false,
    error: null,
    periodFrom: '',
    periodTo: '',
  }),

  getters: {
    metrics(state) {
      return state.dashboard?.metrics ?? {}
    },
    period(state) {
      return state.dashboard?.period ?? null
    },
  },

  actions: {
    async loadBranches() {
      this.loading = true
      this.error = null

      try {
        const { data } = await branchDashboardApi.listMyBranchDashboardBranches()
        this.branches = data.data ?? []

        if (!this.selectedBranchId && this.branches.length > 0) {
          this.selectedBranchId = this.branches[0].id
        }
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load branches.')
      } finally {
        this.loading = false
      }
    },

    async loadDashboard(branchId = this.selectedBranchId) {
      if (!branchId) return

      this.loading = true
      this.error = null
      this.selectedBranchId = branchId
      this.drillDown = null

      const params = {}
      if (this.periodFrom) params.period_from = this.periodFrom
      if (this.periodTo) params.period_to = this.periodTo

      try {
        const { data } = await branchDashboardApi.fetchBranchDashboard(branchId, params)
        this.dashboard = data.data

        if (this.dashboard?.period) {
          this.periodFrom = this.dashboard.period.from ?? this.periodFrom
          this.periodTo = this.dashboard.period.to ?? this.periodTo
        }
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load branch dashboard.')
        this.dashboard = null
      } finally {
        this.loading = false
      }
    },

    async openDrillDown(metric, params = {}) {
      if (!this.selectedBranchId) return

      this.drillLoading = true
      this.error = null

      const query = { ...params }
      if (this.periodFrom) query.period_from = this.periodFrom
      if (this.periodTo) query.period_to = this.periodTo

      try {
        const { data } = await branchDashboardApi.fetchBranchDashboardDrillDown(
          this.selectedBranchId,
          metric,
          query,
        )
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
