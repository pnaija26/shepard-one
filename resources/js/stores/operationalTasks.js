import { defineStore } from 'pinia'
import tasksApi from '../api/operationalTasks'
import { extractApiError } from '../api/client'

export const useOperationalTasksStore = defineStore('operationalTasks', {
  state: () => ({
    tasks: [],
    selectedTask: null,
    loading: false,
    saving: false,
    error: null,
    overdueResult: null,
  }),

  actions: {
    async fetchTasks(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await tasksApi.list(params)
        this.tasks = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load tasks')
      } finally {
        this.loading = false
      }
    },

    async createTask(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await tasksApi.create(payload)
        this.selectedTask = response.data?.data ?? null
        await this.fetchTasks()
        return this.selectedTask
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create task')
        throw error
      } finally {
        this.saving = false
      }
    },

    async selectTask(id) {
      this.loading = true
      this.error = null
      try {
        const response = await tasksApi.show(id)
        this.selectedTask = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to open task')
        this.selectedTask = null
        throw error
      } finally {
        this.loading = false
      }
    },

    async changeStatus(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await tasksApi.changeStatus(id, payload)
        this.selectedTask = response.data?.data ?? this.selectedTask
        await this.fetchTasks()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to update task status')
        throw error
      } finally {
        this.saving = false
      }
    },

    async reassign(id, payload) {
      this.saving = true
      this.error = null
      try {
        const response = await tasksApi.reassign(id, payload)
        this.selectedTask = response.data?.data ?? this.selectedTask
        await this.fetchTasks()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to reassign task')
        throw error
      } finally {
        this.saving = false
      }
    },

    async processOverdue() {
      this.saving = true
      this.error = null
      try {
        const response = await tasksApi.processOverdue()
        this.overdueResult = response.data?.data ?? null
        await this.fetchTasks()
        return this.overdueResult
      } catch (error) {
        this.error = extractApiError(error, 'Unable to process overdue tasks')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
