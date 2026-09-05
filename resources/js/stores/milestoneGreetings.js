import { defineStore } from 'pinia'
import milestoneGreetingsApi from '../api/milestoneGreetings'
import { extractApiError } from '../api/client'

export const useMilestoneGreetingsStore = defineStore('milestoneGreetings', {
  state: () => ({
    configs: [],
    today: [],
    evaluations: [],
    processResult: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchConfigs() {
      this.loading = true
      this.error = null
      try {
        const response = await milestoneGreetingsApi.listConfigs()
        this.configs = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load greeting configs')
      } finally {
        this.loading = false
      }
    },

    async saveConfig(payload) {
      this.saving = true
      this.error = null
      try {
        await milestoneGreetingsApi.upsertConfig(payload)
        await this.fetchConfigs()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to save config')
        throw error
      } finally {
        this.saving = false
      }
    },

    async fetchToday(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await milestoneGreetingsApi.today(params)
        this.today = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load today list')
      } finally {
        this.loading = false
      }
    },

    async fetchEvaluations(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await milestoneGreetingsApi.evaluations(params)
        this.evaluations = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load evaluations')
      } finally {
        this.loading = false
      }
    },

    async process(payload = {}) {
      this.saving = true
      this.error = null
      try {
        const response = await milestoneGreetingsApi.process(payload)
        this.processResult = response.data?.data ?? null
        await this.fetchToday()
        await this.fetchEvaluations()
        return this.processResult
      } catch (error) {
        this.error = extractApiError(error, 'Unable to process greetings')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
