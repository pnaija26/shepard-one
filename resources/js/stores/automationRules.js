import { defineStore } from 'pinia'
import automationRulesApi from '../api/automationRules'
import { extractApiError } from '../api/client'

export const useAutomationRulesStore = defineStore('automationRules', {
  state: () => ({
    rules: [],
    selected: null,
    validation: null,
    simulation: null,
    evaluateResult: null,
    retryResult: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchRules() {
      this.loading = true
      this.error = null
      try {
        const response = await automationRulesApi.list()
        this.rules = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load automation rules')
      } finally {
        this.loading = false
      }
    },

    async createRule(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await automationRulesApi.create(payload)
        this.selected = response.data?.data ?? null
        await this.fetchRules()
        return this.selected
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create automation rule')
        throw error
      } finally {
        this.saving = false
      }
    },

    async selectRule(id) {
      this.loading = true
      this.error = null
      try {
        const response = await automationRulesApi.show(id)
        this.selected = response.data?.data ?? null
        this.validation = null
        this.simulation = null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to open automation rule')
        this.selected = null
        throw error
      } finally {
        this.loading = false
      }
    },

    async updateDraft(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await automationRulesApi.updateDraft(id, payload)
        this.selected = response.data?.data ?? this.selected
        await this.fetchRules()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to update draft')
        throw error
      } finally {
        this.saving = false
      }
    },

    async validate(id) {
      this.saving = true
      this.error = null
      try {
        const response = await automationRulesApi.validate(id)
        this.validation = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to validate rule')
        throw error
      } finally {
        this.saving = false
      }
    },

    async simulate(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await automationRulesApi.simulate(id, payload)
        this.simulation = response.data?.data ?? null
        await this.selectRule(id)
      } catch (error) {
        this.error = extractApiError(error, 'Unable to simulate rule')
        throw error
      } finally {
        this.saving = false
      }
    },

    async publish(id) {
      this.saving = true
      this.error = null
      try {
        const response = await automationRulesApi.publish(id)
        this.selected = response.data?.data ?? this.selected
        await this.fetchRules()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to publish rule')
        throw error
      } finally {
        this.saving = false
      }
    },

    async setEnabled(id, enabled) {
      this.saving = true
      this.error = null
      try {
        const response = await automationRulesApi.setEnabled(id, enabled)
        this.selected = response.data?.data ?? this.selected
        await this.fetchRules()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to update rule status')
        throw error
      } finally {
        this.saving = false
      }
    },

    async evaluate(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await automationRulesApi.evaluate(payload)
        this.evaluateResult = response.data?.data ?? null
        return this.evaluateResult
      } catch (error) {
        this.error = extractApiError(error, 'Unable to evaluate event')
        throw error
      } finally {
        this.saving = false
      }
    },

    async processRetries() {
      this.saving = true
      this.error = null
      try {
        const response = await automationRulesApi.processRetries()
        this.retryResult = response.data?.data ?? null
        return this.retryResult
      } catch (error) {
        this.error = extractApiError(error, 'Unable to process retries')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
