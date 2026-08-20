import { defineStore } from 'pinia'
import api from '@/api/movement'

export const useMovementStore = defineStore('movement', {
  state: () => ({
    movements: [],
    people: [],
    loading: false,
    error: null,
    // Story 1.4/1.5: server-declared scope context for the current view.
    scope: null,
    branchId: null
  }),

  getters: {
    allMovements: (state) => state.movements,

    pendingMovements: (state) => state.movements.filter(m => m.status === 'pending'),

    approvedMovements: (state) => state.movements.filter(m => m.status === 'approved'),

    rejectedMovements: (state) => state.movements.filter(m => m.status === 'rejected'),

    appliedMovements: (state) => state.movements.filter(m => m.applied_at !== null),

    /** Human-readable label for the current data context. */
    scopeLabel: (state) => {
      if (!state.scope) return 'Unknown scope'
      return state.scope === 'church-wide' ? 'Church-wide view (all branches)' : 'Branch view (your branch only)'
    },

    isChurchWide: (state) => state.scope === 'church-wide',

    /** People picker options for the initiate form. */
    peopleOptions: (state) => state.people.map(p => ({
      value: p.id,
      label: `${p.name} (${p.email})`
    }))
  },

  actions: {
    async fetchMovements() {
      this.loading = true
      this.error = null

      try {
        const response = await api.getMovements()
        // Normalize the API envelope ({ data, meta }) or a bare array so
        // state.movements is always iterable — never assign the raw body.
        const body = response.data ?? {}
        this.movements = Array.isArray(body) ? body : (Array.isArray(body.data) ? body.data : [])

        if (body.meta) {
          this.scope = body.meta.scope ?? null
          this.branchId = body.meta.branch_id ?? null
        }
      } catch (error) {
        this.error = error.message || 'Failed to fetch movements'
        console.error('Error fetching movements:', error)
        throw error
      } finally {
        this.loading = false
      }
    },

    async createMovement(movementData) {
      this.error = null

      try {
        const response = await api.createMovement(movementData)
        // Prepend the new movement to local state (list is ordered desc by created_at).
        this.movements.unshift(response.data)
        return response.data
      } catch (error) {
        this.error = error.message || 'Failed to create movement'
        console.error('Error creating movement:', error)
        throw error
      } finally {
        this.loading = false
      }
    },

    async approveMovement(id, reason = null) {
      this.error = null

      try {
        const response = await api.approveMovement(id, reason)
        this._updateLocal(id, response.data)
        return response.data
      } catch (error) {
        this.error = error.message || 'Failed to approve movement'
        console.error('Error approving movement:', error)
        throw error
      } finally {
        this.loading = false
      }
    },

    async rejectMovement(id, reason) {
      this.error = null

      try {
        const response = await api.rejectMovement(id, reason)
        this._updateLocal(id, response.data)
        return response.data
      } catch (error) {
        this.error = error.message || 'Failed to reject movement'
        console.error('Error rejecting movement:', error)
        throw error
      } finally {
        this.loading = false
      }
    },

    async fetchPeople() {
      this.loading = true
      this.error = null

      try {
        const response = await api.getPeople()
        const body = response.data ?? {}
        this.people = Array.isArray(body) ? body : (Array.isArray(body.data) ? body.data : [])
      } catch (error) {
        this.error = error.message || 'Failed to fetch people'
        console.error('Error fetching people:', error)
        throw error
      } finally {
        this.loading = false
      }
    },

    /** Replace a movement in local state by id. */
    _updateLocal(id, updated) {
      const index = this.movements.findIndex(m => m.id === id)
      if (index !== -1) {
        this.movements[index] = updated
      }
    }
  }
})
