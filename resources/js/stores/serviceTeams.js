import { defineStore } from 'pinia'
import serviceTeamsApi from '../api/serviceTeams'
import { extractApiError } from '../api/client'

export const useServiceTeamsStore = defineStore('serviceTeams', {
  state: () => ({
    teams: [],
    assignments: [],
    selectedTeamId: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchTeams(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await serviceTeamsApi.list(params)
        this.teams = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load service teams')
      } finally {
        this.loading = false
      }
    },

    async createTeam(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await serviceTeamsApi.create(payload)
        await this.fetchTeams()
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create service team')
        throw error
      } finally {
        this.saving = false
      }
    },

    async activateTeam(id) {
      this.saving = true
      try {
        await serviceTeamsApi.activate(id)
        await this.fetchTeams()
      } finally {
        this.saving = false
      }
    },

    async archiveTeam(id) {
      this.saving = true
      try {
        await serviceTeamsApi.archive(id)
        await this.fetchTeams()
      } finally {
        this.saving = false
      }
    },

    async selectTeam(id) {
      this.selectedTeamId = id
      await this.fetchAssignments(id)
    },

    async fetchAssignments(teamId) {
      this.loading = true
      this.error = null
      try {
        const response = await serviceTeamsApi.listAssignments(teamId)
        this.assignments = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load team assignments')
      } finally {
        this.loading = false
      }
    },

    async assignMember(teamId, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await serviceTeamsApi.assignMember(teamId, payload)
        await this.fetchAssignments(teamId)
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to assign member')
        throw error
      } finally {
        this.saving = false
      }
    },

    async approveAssignment(assignmentId) {
      this.saving = true
      try {
        await serviceTeamsApi.approveAssignment(assignmentId)
        if (this.selectedTeamId) {
          await this.fetchAssignments(this.selectedTeamId)
        }
      } finally {
        this.saving = false
      }
    },

    async removeAssignment(assignmentId, reason = '') {
      this.saving = true
      try {
        await serviceTeamsApi.removeAssignment(assignmentId, { reason })
        if (this.selectedTeamId) {
          await this.fetchAssignments(this.selectedTeamId)
        }
      } finally {
        this.saving = false
      }
    },
  },
})
