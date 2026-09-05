import { defineStore } from 'pinia'
import teamReportsApi from '../api/teamReports'
import { extractApiError } from '../api/client'

export const useTeamReportsStore = defineStore('teamReports', {
  state: () => ({
    reports: [],
    selectedReport: null,
    metrics: null,
    selectedTeamId: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchReports(teamId) {
      this.selectedTeamId = teamId
      this.loading = true
      this.error = null
      try {
        const response = await teamReportsApi.list(teamId)
        this.reports = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load team reports')
      } finally {
        this.loading = false
      }
    },

    async fetchMetrics(teamId) {
      const response = await teamReportsApi.metrics(teamId)
      this.metrics = response.data?.data ?? null
    },

    async createReport(teamId, payload) {
      this.saving = true
      try {
        const response = await teamReportsApi.create(teamId, payload)
        await this.fetchReports(teamId)
        return response.data?.data
      } finally {
        this.saving = false
      }
    },

    async selectReport(reportId) {
      const response = await teamReportsApi.show(reportId)
      this.selectedReport = response.data?.data ?? null
    },

    async saveDraft(reportId, payload) {
      this.saving = true
      try {
        const response = await teamReportsApi.update(reportId, payload)
        this.selectedReport = response.data?.data ?? this.selectedReport
        if (this.selectedTeamId) {
          await this.fetchReports(this.selectedTeamId)
        }
      } finally {
        this.saving = false
      }
    },

    async submitReport(reportId) {
      this.saving = true
      try {
        const response = await teamReportsApi.submit(reportId)
        this.selectedReport = response.data?.data ?? this.selectedReport
        if (this.selectedTeamId) {
          await this.fetchReports(this.selectedTeamId)
          await this.fetchMetrics(this.selectedTeamId)
        }
      } finally {
        this.saving = false
      }
    },

    async reviewReport(reportId, payload) {
      this.saving = true
      try {
        const response = await teamReportsApi.review(reportId, payload)
        this.selectedReport = response.data?.data ?? this.selectedReport
        if (this.selectedTeamId) {
          await this.fetchReports(this.selectedTeamId)
          await this.fetchMetrics(this.selectedTeamId)
        }
      } finally {
        this.saving = false
      }
    },
  },
})
