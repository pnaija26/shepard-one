import { defineStore } from 'pinia'
import * as churchDocumentsApi from '../api/churchDocuments'
import { extractApiError } from '../api/client'

export const useChurchDocumentsStore = defineStore('churchDocuments', {
  state: () => ({
    catalog: null,
    documents: [],
    selected: null,
    saving: false,
    loading: false,
    error: null,
  }),

  actions: {
    async loadCatalog() {
      const { data } = await churchDocumentsApi.fetchChurchDocumentCatalog()
      this.catalog = data.data
    },

    async loadDocuments(params = {}) {
      this.loading = true
      this.error = null
      try {
        const { data } = await churchDocumentsApi.listChurchDocuments(params)
        this.documents = data.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load documents.')
      } finally {
        this.loading = false
      }
    },

    async uploadDocument(payload) {
      this.saving = true
      this.error = null
      try {
        const { data } = await churchDocumentsApi.uploadChurchDocument(payload)
        this.selected = data.data
        await this.loadDocuments()
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to upload document.')
        throw error
      } finally {
        this.saving = false
      }
    },

    async loadDocument(id) {
      const { data } = await churchDocumentsApi.fetchChurchDocument(id)
      this.selected = data.data
      return data.data
    },

    async replaceVersion(id, payload) {
      this.saving = true
      this.error = null
      try {
        const { data } = await churchDocumentsApi.replaceChurchDocumentVersion(id, payload)
        this.selected = data.data
        await this.loadDocuments()
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to replace document version.')
        throw error
      } finally {
        this.saving = false
      }
    },

    async issueAccess(id, payload) {
      const { data } = await churchDocumentsApi.issueChurchDocumentAccess(id, payload)
      return data.data
    },
  },
})
