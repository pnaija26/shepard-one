import { defineStore } from 'pinia'
import * as dataMigrationsApi from '../api/dataMigrations'
import { extractApiError } from '../api/client'

export const useDataMigrationsStore = defineStore('dataMigrations', {
  state: () => ({
    catalog: null,
    sources: [],
    profile: null,
    mapping: null,
    validationRun: null,
    cutoverPlan: null,
    cutoverRun: null,
    goLiveReport: null,
    saving: false,
    loading: false,
    error: null,
  }),

  actions: {
    async loadCatalog() {
      const { data } = await dataMigrationsApi.fetchDataMigrationCatalog()
      this.catalog = data.data
    },

    async loadSources() {
      this.loading = true
      this.error = null
      try {
        const { data } = await dataMigrationsApi.listDataMigrationSources()
        this.sources = data.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load migration sources.')
      } finally {
        this.loading = false
      }
    },

    async uploadSource(payload) {
      this.saving = true
      this.error = null
      try {
        const { data } = await dataMigrationsApi.createDataMigrationSource(payload)
        await this.loadSources()
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to upload source.')
        throw error
      } finally {
        this.saving = false
      }
    },

    async profileSource(id) {
      const { data } = await dataMigrationsApi.profileDataMigrationSource(id)
      this.profile = data.data
      return data.data
    },

    async createMapping(sourceId, payload) {
      this.saving = true
      this.error = null
      try {
        const { data } = await dataMigrationsApi.createDataMigrationMapping(sourceId, payload)
        this.mapping = data.data
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to save mapping.')
        throw error
      } finally {
        this.saving = false
      }
    },

    async runValidation(mappingId) {
      const { data } = await dataMigrationsApi.runDataMigrationValidation(mappingId)
      this.validationRun = data.data
      return data.data
    },

    async approveMapping(mappingId) {
      const { data } = await dataMigrationsApi.approveDataMigrationMapping(mappingId)
      this.mapping = data.data
      return data.data
    },

    async createCutoverPlan(mappingId, payload) {
      this.saving = true
      this.error = null
      try {
        const { data } = await dataMigrationsApi.createDataMigrationCutoverPlan(mappingId, payload)
        this.cutoverPlan = data.data
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create cutover plan.')
        throw error
      } finally {
        this.saving = false
      }
    },

    async loadCutoverPlan(id) {
      const { data } = await dataMigrationsApi.fetchDataMigrationCutoverPlan(id)
      this.cutoverPlan = data.data
      return data.data
    },

    async runTestMigration(planId, payload = {}) {
      const { data } = await dataMigrationsApi.runDataMigrationTest(planId, payload)
      this.cutoverRun = data.data
      return data.data
    },

    async signOffUat(planId) {
      const { data } = await dataMigrationsApi.signOffDataMigrationUat(planId)
      this.cutoverPlan = data.data
      return data.data
    },

    async executeProduction(planId, payload = {}) {
      const { data } = await dataMigrationsApi.executeDataMigrationProduction(planId, payload)
      this.cutoverRun = data.data
      return data.data
    },

    async approveGoLive(planId) {
      const { data } = await dataMigrationsApi.approveDataMigrationGoLive(planId)
      this.goLiveReport = data.data
      return data.data
    },

    async disposeMigration(planId) {
      const { data } = await dataMigrationsApi.disposeDataMigration(planId)
      this.cutoverPlan = data.data
      return data.data
    },
  },
})
