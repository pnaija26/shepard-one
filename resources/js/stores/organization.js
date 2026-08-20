import { defineStore } from 'pinia'
import api from '@/api/organization'

export const useOrganizationStore = defineStore('organization', {
  state: () => ({
    organizations: [],
    loading: false,
    error: null,
    // Story 1.4: server-declared scope context for the current view.
    // 'church-wide' (HQ consolidated) or 'branch'; branch_id is set only in
    // branch scope. Always comes from the API response meta — never guessed.
    scope: null,
    branchId: null
  }),

  getters: {
    allOrganizations: (state) => state.organizations,

    /** Human-readable label for the current data context (Story 1.4). */
    scopeLabel: (state) => {
      if (!state.scope) return 'Unknown scope'
      return state.scope === 'church-wide' ? 'Church-wide view (all branches)' : 'Branch view (your branch only)'
    },

    isChurchWide: (state) => state.scope === 'church-wide',

    // Nested tree built client-side so hierarchies of any depth render.
    // Cycle-safe: if bad data ever contains a parent loop, nodes that would
    // recurse forever are surfaced as roots instead of crashing the page.
    tree: (state) => {
      const map = new Map()
      for (const org of state.organizations) {
        map.set(org.id, { ...org, children: [] })
      }
      const roots = []
      for (const node of map.values()) {
        if (node.parent_id && map.has(node.parent_id)) {
          // Walk up the parent chain; if we loop back to this node it is part
          // of a cycle and must be treated as a root.
          let cursor = map.get(node.parent_id)
          const visited = new Set([node.id])
          let cyclic = false
          while (cursor && !visited.has(cursor.id)) {
            visited.add(cursor.id)
            if (!cursor.parent_id || !map.has(cursor.parent_id)) break
            cursor = map.get(cursor.parent_id)
          }
          if (cursor && visited.has(cursor.id)) {
            cyclic = true
          }
          if (cyclic) {
            roots.push(node)
          } else {
            map.get(node.parent_id).children.push(node)
          }
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
        // The API body is an envelope ({ data, meta }) — or a bare array on
        // some endpoints. Normalize to an array so state.organizations is
        // always iterable; never assign the raw axios response object.
        const body = response.data ?? {}
        this.organizations = Array.isArray(body) ? body : (Array.isArray(body.data) ? body.data : [])
        // Story 1.4: capture server-declared scope context for the UI banner.
        if (body.meta) {
          this.scope = body.meta.scope ?? null
          this.branchId = body.meta.branch_id ?? null
        }
      } catch (error) {
        this.error = error.message || 'Failed to fetch organizations'
        console.error('Error fetching organizations:', error)
        throw error
      } finally {
        this.loading = false
      }
    },

    async createOrganization(organizationData) {
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