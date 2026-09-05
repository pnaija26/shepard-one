import { defineStore } from 'pinia'
import * as reportSchedulesApi from '../api/reportSchedules'
import { extractApiError } from '../api/client'

export const useReportSchedulesStore = defineStore('reportSchedules', {
  state: () => ({
    catalog: null,
    schedules: [],
    selected: null,
    saving: false,
    loading: false,
    error: null,
  }),

  actions: {
    async loadCatalog() {
      const { data } = await reportSchedulesApi.fetchReportScheduleCatalog()
      this.catalog = data.data
    },

    async loadSchedules() {
      this.loading = true
      this.error = null
      try {
        const { data } = await reportSchedulesApi.listReportSchedules()
        this.schedules = data.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load schedules.')
      } finally {
        this.loading = false
      }
    },

    async createSchedule(payload) {
      this.saving = true
      this.error = null
      try {
        const { data } = await reportSchedulesApi.createReportSchedule(payload)
        this.selected = data.data
        await this.loadSchedules()
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create schedule.')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
