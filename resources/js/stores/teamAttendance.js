import { defineStore } from 'pinia'
import teamAttendanceApi from '../api/teamAttendance'
import { extractApiError } from '../api/client'

export const useTeamAttendanceStore = defineStore('teamAttendance', {
  state: () => ({
    occurrences: [],
    selectedOccurrence: null,
    analysis: null,
    selectedTeamId: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchOccurrences(teamId) {
      this.selectedTeamId = teamId
      this.loading = true
      this.error = null
      try {
        const response = await teamAttendanceApi.listOccurrences(teamId)
        this.occurrences = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load team occurrences')
      } finally {
        this.loading = false
      }
    },

    async createOccurrence(teamId, payload) {
      this.saving = true
      try {
        await teamAttendanceApi.createOccurrence(teamId, payload)
        await this.fetchOccurrences(teamId)
      } finally {
        this.saving = false
      }
    },

    async selectOccurrence(occurrenceId) {
      this.loading = true
      try {
        const response = await teamAttendanceApi.showOccurrence(occurrenceId)
        this.selectedOccurrence = response.data?.data ?? null
      } finally {
        this.loading = false
      }
    },

    async captureAttendance(occurrenceId, entries) {
      this.saving = true
      try {
        await teamAttendanceApi.capture(occurrenceId, entries)
        if (this.selectedOccurrence?.id === occurrenceId) {
          await this.selectOccurrence(occurrenceId)
        }
        if (this.selectedTeamId) {
          await this.fetchAnalysis(this.selectedTeamId)
        }
      } finally {
        this.saving = false
      }
    },

    async fetchAnalysis(teamId, params = {}) {
      this.loading = true
      try {
        const response = await teamAttendanceApi.analyze(teamId, params)
        this.analysis = response.data?.data ?? null
      } finally {
        this.loading = false
      }
    },

    async correctRecord(recordId, payload) {
      this.saving = true
      try {
        await teamAttendanceApi.correct(recordId, payload)
        if (this.selectedOccurrence?.id) {
          await this.selectOccurrence(this.selectedOccurrence.id)
        }
        if (this.selectedTeamId) {
          await this.fetchAnalysis(this.selectedTeamId)
        }
      } finally {
        this.saving = false
      }
    },
  },
})
