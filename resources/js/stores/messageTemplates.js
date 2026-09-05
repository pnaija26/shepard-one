import { defineStore } from 'pinia'
import messageTemplatesApi from '../api/messageTemplates'
import { extractApiError } from '../api/client'

export const useMessageTemplatesStore = defineStore('messageTemplates', {
  state: () => ({
    templates: [],
    selected: null,
    validation: null,
    preview: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchTemplates() {
      this.loading = true
      this.error = null
      try {
        const response = await messageTemplatesApi.list()
        this.templates = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load templates')
      } finally {
        this.loading = false
      }
    },

    async createTemplate(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await messageTemplatesApi.create(payload)
        this.selected = response.data?.data ?? null
        await this.fetchTemplates()
        return this.selected
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create template')
        throw error
      } finally {
        this.saving = false
      }
    },

    async selectTemplate(id) {
      this.loading = true
      this.error = null
      try {
        const response = await messageTemplatesApi.show(id)
        this.selected = response.data?.data ?? null
        this.validation = null
        this.preview = null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to open template')
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
        const response = await messageTemplatesApi.updateDraft(id, payload)
        this.selected = response.data?.data ?? this.selected
        await this.fetchTemplates()
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
        const response = await messageTemplatesApi.validate(id)
        this.validation = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to validate template')
        throw error
      } finally {
        this.saving = false
      }
    },

    async preview(id, payload = {}) {
      this.saving = true
      this.error = null
      try {
        const response = await messageTemplatesApi.preview(id, payload)
        this.preview = response.data?.data ?? null
        await this.selectTemplate(id)
      } catch (error) {
        this.error = extractApiError(error, 'Unable to preview template')
        throw error
      } finally {
        this.saving = false
      }
    },

    async publish(id, payload = {}) {
      this.saving = true
      this.error = null
      try {
        const response = await messageTemplatesApi.publish(id, payload)
        this.selected = response.data?.data ?? this.selected
        await this.fetchTemplates()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to publish template')
        throw error
      } finally {
        this.saving = false
      }
    },

    async retire(id) {
      this.saving = true
      this.error = null
      try {
        const response = await messageTemplatesApi.retire(id)
        this.selected = response.data?.data ?? this.selected
        await this.fetchTemplates()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to retire template')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
