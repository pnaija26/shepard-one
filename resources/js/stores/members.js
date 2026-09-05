import { defineStore } from 'pinia'
import api from '@/api/members'
import { extractApiError } from '@/api/client'

export const useMembersStore = defineStore('members', {
  state: () => ({
    members: [],
    selectedMember: null,
    search: '',
    loading: false,
    saving: false,
    error: null,
  }),

  getters: {
    filteredMembers: (state) => {
      if (!state.search) return state.members
      const term = state.search.toLowerCase()
      return state.members.filter((member) =>
        member.full_name?.toLowerCase().includes(term)
        || member.email?.toLowerCase().includes(term)
        || member.membership_id?.toLowerCase().includes(term),
      )
    },
  },

  actions: {
    async fetchMembers() {
      this.loading = true
      this.error = null

      try {
        const response = await api.listMembers()
        this.members = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Failed to load members')
        throw error
      } finally {
        this.loading = false
      }
    },

    async fetchMember(id) {
      this.loading = true
      this.error = null

      try {
        const response = await api.getMember(id)
        this.selectedMember = response.data?.data ?? null
        return this.selectedMember
      } catch (error) {
        this.error = extractApiError(error, 'Failed to load member')
        throw error
      } finally {
        this.loading = false
      }
    },

    async createMember(payload, force = false) {
      this.saving = true
      this.error = null

      try {
        const response = await api.createMember({ ...payload, force })
        const created = response.data?.data ?? response.data
        if (created) {
          this.members.unshift(created)
        }
        return { created, duplicate: null }
      } catch (error) {
        const data = error?.response?.data
        if (data?.duplicate_review_required) {
          return { created: null, duplicate: data }
        }
        this.error = extractApiError(error, 'Failed to register member')
        throw error
      } finally {
        this.saving = false
      }
    },

    async updateMember(id, payload) {
      this.saving = true
      this.error = null

      try {
        const response = await api.updateMember(id, payload)
        const updated = response.data?.data ?? response.data
        const idx = this.members.findIndex((m) => m.id === id)
        if (idx !== -1 && updated) {
          this.members[idx] = updated
        }
        this.selectedMember = updated
        return updated
      } catch (error) {
        this.error = extractApiError(error, 'Failed to update member')
        throw error
      } finally {
        this.saving = false
      }
    },

    async archiveMember(id, reason = null) {
      this.saving = true
      this.error = null

      try {
        const response = await api.archiveMember(id, reason)
        const updated = response.data?.data ?? response.data
        const idx = this.members.findIndex((m) => m.id === id)
        if (idx !== -1 && updated) {
          this.members[idx] = updated
        }
        return updated
      } catch (error) {
        this.error = extractApiError(error, 'Failed to archive member')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
