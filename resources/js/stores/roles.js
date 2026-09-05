import { defineStore } from 'pinia'
import api from '@/api/roles'
import { extractApiError } from '@/api/client'

export const useRoleStore = defineStore('roles', {
  state: () => ({
    roles: [],
    loading: false,
    saving: false,
    error: null,
  }),

  getters: {
    allRoles: (state) => state.roles,
  },

  actions: {
    async fetchRoles() {
      this.loading = true
      this.error = null

      try {
        const response = await api.listRoles()
        const body = response.data ?? {}
        this.roles = Array.isArray(body) ? body : (Array.isArray(body.data) ? body.data : [])
      } catch (error) {
        this.error = extractApiError(error, 'Failed to load roles')
        throw error
      } finally {
        this.loading = false
      }
    },

    async createRole(payload) {
      this.saving = true
      this.error = null

      try {
        const response = await api.createRole(payload)
        const role = response.data?.data ?? response.data
        if (role) {
          this.roles.push(role)
        }
        return role
      } catch (error) {
        this.error = extractApiError(error, 'Failed to create role')
        throw error
      } finally {
        this.saving = false
      }
    },

    async deleteRole(id, payload = {}) {
      this.error = null

      try {
        await api.deleteRole(id, payload)
        this.roles = this.roles.filter((role) => role.id !== id)
      } catch (error) {
        this.error = extractApiError(error, 'Failed to delete role')
        throw error
      }
    },
  },
})
