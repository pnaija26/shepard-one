import { defineStore } from 'pinia'
import api from '@/api/config'
import { extractApiError } from '@/api/client'

export const useConfigurationStore = defineStore('configuration', {
  state: () => ({
    settings: [],
    categories: [],
    selectedCategory: '',
    loading: false,
    saving: false,
    error: null,
  }),

  getters: {
    filteredSettings: (state) => {
      if (!state.selectedCategory) return state.settings
      return state.settings.filter((s) => s.category === state.selectedCategory)
    },
  },

  actions: {
    async fetchAll() {
      this.loading = true
      this.error = null

      try {
        const [settingsRes, categoriesRes] = await Promise.all([
          api.listSettings(),
          api.listCategories(),
        ])

        const settingsBody = settingsRes.data ?? {}
        this.settings = Array.isArray(settingsBody) ? settingsBody : (settingsBody.data ?? [])

        const categoriesBody = categoriesRes.data ?? {}
        this.categories = Array.isArray(categoriesBody) ? categoriesBody : (categoriesBody.data ?? [])

        // Default to "All" so the first category tab cannot hide every setting.
      } catch (error) {
        this.error = extractApiError(error, 'Failed to load configuration')
        throw error
      } finally {
        this.loading = false
      }
    },

    async stageSetting(key, value) {
      this.saving = true
      this.error = null

      try {
        const response = await api.updateSetting(key, { value })
        const updated = response.data?.data ?? response.data
        const idx = this.settings.findIndex((s) => s.key === key)
        if (idx !== -1 && updated) {
          this.settings[idx] = updated
        }
        return updated
      } catch (error) {
        this.error = extractApiError(error, 'Failed to save draft')
        throw error
      } finally {
        this.saving = false
      }
    },

    async publishSetting(key) {
      this.saving = true
      this.error = null

      try {
        const response = await api.publishSetting(key)
        const updated = response.data?.data ?? response.data
        const idx = this.settings.findIndex((s) => s.key === key)
        if (idx !== -1 && updated) {
          this.settings[idx] = updated
        }
        return updated
      } catch (error) {
        this.error = extractApiError(error, 'Failed to publish setting')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
