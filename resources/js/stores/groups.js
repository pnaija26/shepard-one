import { defineStore } from 'pinia'
import groupsApi from '../api/groups'
import { extractApiError } from '../api/client'

export const useChurchGroupsStore = defineStore('churchGroups', {
  state: () => ({
    groups: [],
    selectedGroup: null,
    meetings: [],
    meetingDashboard: null,
    loading: false,
    saving: false,
    error: null,
    message: null,
  }),

  actions: {
    async fetchGroups(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await groupsApi.list(params)
        this.groups = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load church groups')
      } finally {
        this.loading = false
      }
    },

    async createGroup(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await groupsApi.create(payload)
        await this.fetchGroups()
        return response.data?.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create church group')
        throw error
      } finally {
        this.saving = false
      }
    },

    async selectGroup(id) {
      this.loading = true
      this.error = null
      try {
        const response = await groupsApi.show(id)
        this.selectedGroup = response.data?.data ?? null
        await Promise.all([
          this.fetchMeetings(id),
          this.fetchMeetingDashboard(id),
        ])
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load church group')
      } finally {
        this.loading = false
      }
    },

    async fetchMeetings(groupId) {
      try {
        const response = await groupsApi.listMeetings(groupId)
        this.meetings = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load group meetings')
      }
    },

    async fetchMeetingDashboard(groupId) {
      try {
        const response = await groupsApi.meetingDashboard(groupId)
        this.meetingDashboard = response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load meeting dashboard')
      }
    },

    async scheduleMeeting(groupId, payload) {
      this.saving = true
      try {
        await groupsApi.scheduleMeeting(groupId, payload)
        await this.fetchMeetings(groupId)
      } finally {
        this.saving = false
      }
    },

    async recordMeeting(meetingId, payload) {
      this.saving = true
      try {
        await groupsApi.recordMeeting(meetingId, payload)
        if (this.selectedGroup?.id) {
          await Promise.all([
            this.fetchMeetings(this.selectedGroup.id),
            this.fetchMeetingDashboard(this.selectedGroup.id),
          ])
        }
      } finally {
        this.saving = false
      }
    },

    async activateGroup(id) {
      this.saving = true
      try {
        const response = await groupsApi.activate(id)
        this.selectedGroup = response.data?.data ?? this.selectedGroup
        await this.fetchGroups()
      } finally {
        this.saving = false
      }
    },

    async assignMember(groupId, payload) {
      this.saving = true
      try {
        await groupsApi.assignMember(groupId, payload)
        await this.selectGroup(groupId)
      } finally {
        this.saving = false
      }
    },

    async approveJoinRequest(requestId, groupId) {
      this.saving = true
      try {
        await groupsApi.reviewJoinRequest(requestId, { decision: 'approved', role: 'member' })
        await this.selectGroup(groupId)
      } finally {
        this.saving = false
      }
    },
  },
})
