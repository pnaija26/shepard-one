import { defineStore } from 'pinia'
import workflowsApi from '../api/workflows'
import { extractApiError } from '../api/client'

export const useWorkflowsStore = defineStore('workflows', {
  state: () => ({
    workflows: [],
    selected: null,
    visualization: null,
    validation: null,
    testResult: null,
    instances: [],
    selectedInstance: null,
    deadlineResult: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchWorkflows() {
      this.loading = true
      this.error = null
      try {
        const response = await workflowsApi.list()
        this.workflows = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load workflows')
      } finally {
        this.loading = false
      }
    },

    async createWorkflow(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await workflowsApi.create(payload)
        this.selected = response.data?.data ?? null
        await this.fetchWorkflows()
        return this.selected
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create workflow')
        throw error
      } finally {
        this.saving = false
      }
    },

    async selectWorkflow(id) {
      this.loading = true
      this.error = null
      try {
        const response = await workflowsApi.show(id)
        this.selected = response.data?.data ?? null
        this.visualization = null
        this.validation = null
        this.testResult = null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to open workflow')
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
        const response = await workflowsApi.updateDraft(id, payload)
        this.selected = response.data?.data ?? this.selected
        await this.fetchWorkflows()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to update draft')
        throw error
      } finally {
        this.saving = false
      }
    },

    async visualize(id) {
      this.error = null
      try {
        const response = await workflowsApi.visualize(id)
        this.visualization = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to visualize workflow')
        throw error
      }
    },

    async validate(id) {
      this.saving = true
      this.error = null
      try {
        const response = await workflowsApi.validate(id)
        this.validation = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to validate workflow')
        throw error
      } finally {
        this.saving = false
      }
    },

    async test(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await workflowsApi.test(id, payload)
        this.testResult = response.data?.data ?? null
        await this.selectWorkflow(id)
      } catch (error) {
        this.error = extractApiError(error, 'Unable to test workflow')
        throw error
      } finally {
        this.saving = false
      }
    },

    async publish(id, payload = {}) {
      this.saving = true
      this.error = null
      try {
        const response = await workflowsApi.publish(id, payload)
        this.selected = response.data?.data ?? this.selected
        await this.fetchWorkflows()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to publish workflow')
        throw error
      } finally {
        this.saving = false
      }
    },

    async fetchInstances(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await workflowsApi.listInstances(params)
        this.instances = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load workflow instances')
      } finally {
        this.loading = false
      }
    },

    async startInstance(workflowId, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await workflowsApi.startInstance(workflowId, payload)
        this.selectedInstance = response.data?.data ?? null
        await this.fetchInstances({ workflow_id: workflowId })
        return this.selectedInstance
      } catch (error) {
        this.error = extractApiError(error, 'Unable to start workflow instance')
        throw error
      } finally {
        this.saving = false
      }
    },

    async selectInstance(id) {
      this.loading = true
      this.error = null
      try {
        const response = await workflowsApi.showInstance(id)
        this.selectedInstance = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to open workflow instance')
        this.selectedInstance = null
        throw error
      } finally {
        this.loading = false
      }
    },

    async actOnInstance(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await workflowsApi.act(id, payload)
        this.selectedInstance = response.data?.data ?? this.selectedInstance
        await this.fetchInstances()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to advance workflow')
        throw error
      } finally {
        this.saving = false
      }
    },

    async processDeadlines() {
      this.saving = true
      this.error = null
      try {
        const response = await workflowsApi.processDeadlines()
        this.deadlineResult = response.data?.data ?? null
        await this.fetchInstances()
        return this.deadlineResult
      } catch (error) {
        this.error = extractApiError(error, 'Unable to process deadlines')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
