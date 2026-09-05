import { defineStore } from 'pinia'
import * as teamReportFormsApi from '../api/teamReportForms'

export const useTeamReportFormsStore = defineStore('teamReportForms', {
  state: () => ({
    forms: [],
    selectedForm: null,
    preview: null,
    teamForm: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async loadForms() {
      this.loading = true
      this.error = null
      try {
        const { data } = await teamReportFormsApi.listTeamReportForms()
        this.forms = data.data ?? []
      } catch (error) {
        this.error = error.response?.data?.message ?? 'Unable to load report forms.'
      } finally {
        this.loading = false
      }
    },

    async createForm(payload) {
      this.saving = true
      this.error = null
      try {
        const { data } = await teamReportFormsApi.createTeamReportForm(payload)
        this.forms.unshift(data.data)
        this.selectedForm = data.data
        return data.data
      } catch (error) {
        this.error = error.response?.data?.message ?? 'Unable to create report form.'
        throw error
      } finally {
        this.saving = false
      }
    },

    async selectForm(formId) {
      this.loading = true
      this.error = null
      try {
        const { data } = await teamReportFormsApi.fetchTeamReportForm(formId)
        this.selectedForm = data.data
      } catch (error) {
        this.error = error.response?.data?.message ?? 'Unable to load report form.'
      } finally {
        this.loading = false
      }
    },

    async saveDraft(formId, payload) {
      this.saving = true
      this.error = null
      try {
        const { data } = await teamReportFormsApi.updateTeamReportFormDraft(formId, payload)
        this.selectedForm = data.data
        const index = this.forms.findIndex((form) => form.id === formId)
        if (index >= 0) {
          this.forms[index] = data.data
        }
        return data.data
      } catch (error) {
        this.error = error.response?.data?.message ?? 'Unable to save draft form.'
        throw error
      } finally {
        this.saving = false
      }
    },

    async previewForm(formId) {
      this.error = null
      try {
        const { data } = await teamReportFormsApi.previewTeamReportForm(formId)
        this.preview = data.data
        return data.data
      } catch (error) {
        this.error = error.response?.data?.message ?? 'Unable to preview report form.'
        throw error
      }
    },

    async publishForm(formId, teamIds) {
      this.saving = true
      this.error = null
      try {
        const { data } = await teamReportFormsApi.publishTeamReportForm(formId, { team_ids: teamIds })
        this.selectedForm = data.data
        return data.data
      } catch (error) {
        this.error = error.response?.data?.message ?? 'Unable to publish report form.'
        throw error
      } finally {
        this.saving = false
      }
    },

    async loadTeamForm(teamId) {
      this.error = null
      try {
        const { data } = await teamReportFormsApi.fetchTeamReportFormForTeam(teamId)
        this.teamForm = data.data
        return data.data
      } catch (error) {
        this.error = error.response?.data?.message ?? 'Unable to load team report form.'
        throw error
      }
    },
  },
})
