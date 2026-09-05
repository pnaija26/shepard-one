import { defineStore } from 'pinia'
import * as teamDashboardApi from '../api/teamDashboard'
import { extractApiError } from '../api/client'

export const useTeamDashboardStore = defineStore('teamDashboard', {
  state: () => ({
    teams: [],
    dashboard: null,
    drillDown: null,
    selectedTeamId: null,
    loading: false,
    drillLoading: false,
    syncLoading: false,
    error: null,
    conflict: null,
  }),

  getters: {
    priorityActions(state) {
      return state.dashboard?.priority_actions ?? []
    },
    version(state) {
      return state.dashboard?.version ?? null
    },
  },

  actions: {
    async loadTeams() {
      this.loading = true
      this.error = null
      try {
        const { data } = await teamDashboardApi.listMyTeamDashboardTeams()
        this.teams = data.data ?? []
        if (!this.selectedTeamId && this.teams.length > 0) {
          this.selectedTeamId = this.teams[0].id
        }
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load teams.')
      } finally {
        this.loading = false
      }
    },

    async loadDashboard(teamId = this.selectedTeamId) {
      if (!teamId) return

      this.loading = true
      this.error = null
      this.conflict = null
      this.selectedTeamId = teamId
      this.drillDown = null

      try {
        const { data } = await teamDashboardApi.fetchTeamDashboard(teamId)
        this.dashboard = data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load team dashboard.')
        this.dashboard = null
      } finally {
        this.loading = false
      }
    },

    async syncAfterAction(action = 'refresh', expectedVersion = this.version) {
      if (!this.selectedTeamId) return null

      this.syncLoading = true
      this.conflict = null
      this.error = null

      try {
        const { data } = await teamDashboardApi.syncTeamDashboard(this.selectedTeamId, {
          expected_version: expectedVersion ?? '',
          action,
        })
        this.dashboard = data.data
        return this.dashboard
      } catch (error) {
        if (error.response?.status === 409) {
          this.conflict = {
            message: error.response.data?.message ?? 'Dashboard changed on another device.',
            currentVersion: error.response.data?.current_version,
          }
          this.error = this.conflict.message
        } else {
          this.error = extractApiError(error, 'Unable to refresh dashboard.')
        }
        throw error
      } finally {
        this.syncLoading = false
      }
    },

    async resolveConflict() {
      if (!this.conflict?.currentVersion) {
        return this.loadDashboard()
      }
      return this.syncAfterAction('conflict_resolve', '')
    },

    async openDrillDown(widget, params = {}) {
      if (!this.selectedTeamId) return

      this.drillLoading = true
      this.error = null

      try {
        const { data } = await teamDashboardApi.fetchTeamDashboardDrillDown(this.selectedTeamId, widget, params)
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
