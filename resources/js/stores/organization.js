import { defineStore } from 'pinia'
import api from '@/api/organization'

export const useOrganizationStore = defineStore('organization', {
  state: () => ({
    organizations: [],
    loading: false,
    error: null
  }),

  getters: {
    allOrganizations: (state) => state.organizations,

    // Nested tree built client-side so hierarchies of any depth render.
    tree: (state) => {
      const map = new Map()
      for (const org of state.organizations) {
        map.set(org.id, { ...org, children: [] })
      }
      const roots = []
      for (const node of map.values()) {
        if (node.parent_id && map.has(node.parent_id)) {
          map.get(node.parent_id).children.push(node)
        } else {
          roots.push(node)
        }
      }
      return roots
    },

    rootOrganizations: (state, getters) => getters.tree
  },

  actions: {
    async fetchOrganizations() {
      this.loading = true
      this.error = null
      
      try {
        const response = await api.getOrganizations()
        this.organizations = response.data
      } catch (error) {
        this.error = error.message || 'Failed to fetch organizations'
        console.error('Error fetching organizations:', error)
        throw error
      } finally {
        this.loading = false
      }
    },

    async createOrganization(organizationData) {
      this.loading = true
      this.error = null
      
      try {
        const response = await api.createOrganization(organizationData)
        // Add the new organization to our local state
        this.organizations.push(response.data)
        return response.data
      } catch (error) {
        this.error = error.message || 'Failed to create organization'
        console.error('Error creating organization:', error)
        throw error
      } finally {
        this.loading = false
      }
    },

    async updateOrganization(id, organizationData) {
      this.loading = true
      this.error = null
      
      try {
        const response = await api.updateOrganization(id, organizationData)
        // Update the organization in our local state
        const index = this.organizations.findIndex(org => org.id === id)
        if (index !== -1) {
          this.organizations[index] = response.data
        }
        return response.data
      } catch (error) {
        this.error = error.message || 'Failed to update organization'
        console.error('Error updating organization:', error)
        throw error
      } finally {
        this.loading = false
      }
    },

    async deleteOrganization(id) {
      this.loading = true
      this.error = null
      
      try {
        await api.deleteOrganization(id)
        // Remove the organization from our local state
        const index = this.organizations.findIndex(org => org.id === id)
        if (index !== -1) {
          this.organizations.splice(index, 1)
        }
      } catch (error) {
        this.error = error.message || 'Failed to delete organization'
        console.error('Error deleting organization:', error)
        throw error
      } finally {
        this.loading = false
      }
    }
  }
})