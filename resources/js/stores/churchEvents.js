import { defineStore } from 'pinia'
import eventsApi from '../api/events'
import { extractApiError } from '../api/client'

export const useChurchEventStore = defineStore('churchEvents', {
  state: () => ({
    events: [],
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchEvents() {
      this.loading = true
      this.error = null
      try {
        const response = await eventsApi.listEvents()
        this.events = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load events')
      } finally {
        this.loading = false
      }
    },

    async createDraft(payload) {
      this.saving = true
      this.error = null
      try {
        await eventsApi.createEvent(payload)
        await this.fetchEvents()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create event')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
