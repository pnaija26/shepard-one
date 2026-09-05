import { defineStore } from 'pinia'
import attendanceApi from '../api/attendance'
import { extractApiError } from '../api/client'

export const useAttendanceStore = defineStore('attendance', {
  state: () => ({
    rules: [],
    exceptions: [],
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchRules() {
      this.loading = true
      this.error = null
      try {
        const response = await attendanceApi.listRules()
        this.rules = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load attendance rules')
      } finally {
        this.loading = false
      }
    },

    async fetchExceptions() {
      this.loading = true
      this.error = null
      try {
        const response = await attendanceApi.listExceptions()
        this.exceptions = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load attendance exceptions')
      } finally {
        this.loading = false
      }
    },

    async createAndPublishRule(payload) {
      this.saving = true
      this.error = null
      try {
        const created = await attendanceApi.createRule(payload)
        const ruleId = created.data?.data?.id
        await attendanceApi.publishRule(ruleId, { parameters: payload.parameters })
        await Promise.all([this.fetchRules(), this.fetchExceptions()])
      } catch (error) {
        this.error = extractApiError(error, 'Unable to save attendance rule')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
