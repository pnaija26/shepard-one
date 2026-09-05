import { defineStore } from 'pinia'
import api from '@/api/audit'
import { extractApiError } from '@/api/client'

export const useAuditStore = defineStore('audit', {
  state: () => ({
    events: [],
    selectedEvent: null,
    meta: null,
    filters: {
      from: '',
      to: '',
      actor_id: '',
      branch_id: '',
      action: '',
      module: '',
      category: '',
    },
    loading: false,
    exporting: false,
    error: null,
  }),

  actions: {
    async fetchEvents(page = 1) {
      this.loading = true
      this.error = null

      try {
        const params = { page, per_page: 25 }
        Object.entries(this.filters).forEach(([key, value]) => {
          if (value !== '' && value !== null) {
            params[key] = value
          }
        })

        const response = await api.listEvents(params)
        this.events = response.data?.data ?? []
        this.meta = response.data?.meta ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Failed to load audit events')
        throw error
      } finally {
        this.loading = false
      }
    },

    async fetchEvent(id) {
      this.loading = true
      this.error = null

      try {
        const response = await api.getEvent(id)
        this.selectedEvent = response.data?.data ?? null
        return this.selectedEvent
      } catch (error) {
        this.error = extractApiError(error, 'Failed to load audit event')
        throw error
      } finally {
        this.loading = false
      }
    },

    async exportEvents() {
      this.exporting = true
      this.error = null

      try {
        const params = {}
        Object.entries(this.filters).forEach(([key, value]) => {
          if (value !== '' && value !== null) {
            params[key] = value
          }
        })

        const response = await api.exportEvents(params)
        const rows = response.data?.data ?? []
        const blob = new Blob([JSON.stringify(rows, null, 2)], { type: 'application/json' })
        const url = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = url
        link.download = `audit-export-${new Date().toISOString().slice(0, 10)}.json`
        link.click()
        URL.revokeObjectURL(url)
        return rows
      } catch (error) {
        this.error = extractApiError(error, 'Failed to export audit events')
        throw error
      } finally {
        this.exporting = false
      }
    },

    resetFilters() {
      this.filters = {
        from: '',
        to: '',
        actor_id: '',
        branch_id: '',
        action: '',
        module: '',
        category: '',
      }
    },
  },
})
