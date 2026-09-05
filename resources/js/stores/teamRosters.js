import { defineStore } from 'pinia'
import teamRostersApi from '../api/teamRosters'
import { extractApiError } from '../api/client'

export const useTeamRostersStore = defineStore('teamRosters', {
  state: () => ({
    rosters: [],
    selectedRoster: null,
    validation: null,
    mySlots: [],
    selectedTeamId: null,
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchRosters(teamId) {
      this.selectedTeamId = teamId
      this.loading = true
      this.error = null
      try {
        const response = await teamRostersApi.list(teamId)
        this.rosters = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load rosters')
      } finally {
        this.loading = false
      }
    },

    async createRoster(teamId, payload) {
      this.saving = true
      try {
        const response = await teamRostersApi.create(teamId, payload)
        await this.fetchRosters(teamId)
        return response.data?.data
      } finally {
        this.saving = false
      }
    },

    async selectRoster(rosterId) {
      this.loading = true
      try {
        const response = await teamRostersApi.show(rosterId)
        this.selectedRoster = response.data?.data ?? null
      } finally {
        this.loading = false
      }
    },

    async addSlot(rosterId, payload) {
      this.saving = true
      try {
        await teamRostersApi.addSlot(rosterId, payload)
        await this.selectRoster(rosterId)
      } finally {
        this.saving = false
      }
    },

    async validateRoster(rosterId) {
      const response = await teamRostersApi.validate(rosterId)
      this.validation = response.data?.data ?? null
      return this.validation
    },

    async publishRoster(rosterId, payload = {}) {
      this.saving = true
      try {
        const response = await teamRostersApi.publish(rosterId, payload)
        this.selectedRoster = response.data?.data ?? this.selectedRoster
        if (this.selectedTeamId) {
          await this.fetchRosters(this.selectedTeamId)
        }
      } finally {
        this.saving = false
      }
    },

    async substituteSlot(slotId, payload) {
      this.saving = true
      try {
        await teamRostersApi.substitute(slotId, payload)
        if (this.selectedRoster?.id) {
          await this.selectRoster(this.selectedRoster.id)
        }
      } finally {
        this.saving = false
      }
    },

    async fetchMySlots() {
      this.loading = true
      try {
        const response = await teamRostersApi.mySlots()
        this.mySlots = response.data?.data ?? []
      } finally {
        this.loading = false
      }
    },

    async respondToSlot(slotId, payload) {
      this.saving = true
      try {
        await teamRostersApi.respond(slotId, payload)
        await this.fetchMySlots()
      } finally {
        this.saving = false
      }
    },
  },
})
