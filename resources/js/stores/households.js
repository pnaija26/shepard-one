import { defineStore } from 'pinia'
import api from '@/api/households'
import { extractApiError } from '@/api/client'

export const useHouseholdsStore = defineStore('households', {
  state: () => ({
    households: [],
    selectedHousehold: null,
    loading: false,
    saving: false,
    error: null,
    overwriteRequired: null,
  }),

  actions: {
    async fetchHouseholds() {
      this.loading = true
      this.error = null

      try {
        const response = await api.listHouseholds()
        this.households = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Failed to load households')
        throw error
      } finally {
        this.loading = false
      }
    },

    async fetchHousehold(id) {
      this.loading = true
      this.error = null

      try {
        const response = await api.getHousehold(id)
        this.selectedHousehold = response.data?.data ?? null
        return this.selectedHousehold
      } catch (error) {
        this.error = extractApiError(error, 'Failed to load household')
        throw error
      } finally {
        this.loading = false
      }
    },

    async createHousehold(payload) {
      this.saving = true
      this.error = null

      try {
        const response = await api.createHousehold(payload)
        const created = response.data?.data ?? response.data
        if (created) this.households.unshift(created)
        return created
      } catch (error) {
        this.error = extractApiError(error, 'Failed to create household')
        throw error
      } finally {
        this.saving = false
      }
    },

    async updateHousehold(id, payload, confirmOverwrite = false) {
      this.saving = true
      this.error = null
      this.overwriteRequired = null

      try {
        const response = await api.updateHousehold(id, { ...payload, confirm_overwrite: confirmOverwrite })
        const updated = response.data?.data ?? response.data
        this.selectedHousehold = updated
        const idx = this.households.findIndex((h) => h.id === id)
        if (idx !== -1 && updated) this.households[idx] = updated
        return updated
      } catch (error) {
        const data = error?.response?.data
        if (data?.confirm_overwrite_required) {
          this.overwriteRequired = data
          return null
        }
        this.error = extractApiError(error, 'Failed to update household')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
