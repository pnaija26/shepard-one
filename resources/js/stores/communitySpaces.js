import { defineStore } from 'pinia'
import communitySpacesApi from '../api/communitySpaces'
import { extractApiError } from '../api/client'

export const useCommunitySpacesStore = defineStore('communitySpaces', {
  state: () => ({
    spaces: [],
    selected: null,
    messages: [],
    searchResults: [],
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchSpaces() {
      this.loading = true
      this.error = null
      try {
        const response = await communitySpacesApi.list()
        this.spaces = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load community spaces')
      } finally {
        this.loading = false
      }
    },

    async create(payload) {
      this.saving = true
      this.error = null
      try {
        const response = await communitySpacesApi.create(payload)
        this.selected = response.data?.data ?? null
        await this.fetchSpaces()
        if (this.selected?.id) {
          await this.select(this.selected.id)
        }
        return this.selected
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create space')
        throw error
      } finally {
        this.saving = false
      }
    },

    async select(id) {
      this.loading = true
      this.error = null
      try {
        const response = await communitySpacesApi.show(id)
        this.selected = response.data?.data ?? null
        await this.fetchMessages(id)
        this.searchResults = []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to open space')
        this.selected = null
        throw error
      } finally {
        this.loading = false
      }
    },

    async fetchMessages(id) {
      try {
        const response = await communitySpacesApi.messages(id)
        this.messages = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load messages')
      }
    },

    async postMessage(id, payload) {
      this.saving = true
      this.error = null
      try {
        await communitySpacesApi.postMessage(id, payload)
        await this.fetchMessages(id)
      } catch (error) {
        this.error = extractApiError(error, 'Unable to post message')
        throw error
      } finally {
        this.saving = false
      }
    },

    async addMember(id, payload) {
      this.saving = true
      this.error = null
      try {
        await communitySpacesApi.addMember(id, payload)
        await this.select(id)
      } catch (error) {
        this.error = extractApiError(error, 'Unable to add member')
        throw error
      } finally {
        this.saving = false
      }
    },

    async search(id, q) {
      this.loading = true
      this.error = null
      try {
        const response = await communitySpacesApi.search(id, q)
        this.searchResults = response.data?.data ?? []
      } catch (error) {
        this.error = extractApiError(error, 'Unable to search messages')
        throw error
      } finally {
        this.loading = false
      }
    },

    async pin(spaceId, messageId) {
      this.saving = true
      this.error = null
      try {
        await communitySpacesApi.pin(spaceId, messageId)
        await this.fetchMessages(spaceId)
      } catch (error) {
        this.error = extractApiError(error, 'Unable to pin message')
        throw error
      } finally {
        this.saving = false
      }
    },

    async remove(spaceId, messageId, reason) {
      this.saving = true
      this.error = null
      try {
        await communitySpacesApi.remove(spaceId, messageId, { reason })
        await this.fetchMessages(spaceId)
      } catch (error) {
        this.error = extractApiError(error, 'Unable to remove message')
        throw error
      } finally {
        this.saving = false
      }
    },

    async report(spaceId, messageId, reason) {
      this.saving = true
      this.error = null
      try {
        await communitySpacesApi.report(spaceId, messageId, { reason })
      } catch (error) {
        this.error = extractApiError(error, 'Unable to report message')
        throw error
      } finally {
        this.saving = false
      }
    },

    async moderateParticipant(spaceId, userId, payload) {
      this.saving = true
      this.error = null
      try {
        await communitySpacesApi.moderateParticipant(spaceId, userId, payload)
        await this.select(spaceId)
      } catch (error) {
        this.error = extractApiError(error, 'Unable to moderate participant')
        throw error
      } finally {
        this.saving = false
      }
    },

    async configureIntegration(id, payload) {
      this.saving = true
      this.error = null
      try {
        await communitySpacesApi.configureIntegration(id, payload)
        await this.select(id)
      } catch (error) {
        this.error = extractApiError(error, 'Unable to configure integration')
        throw error
      } finally {
        this.saving = false
      }
    },
  },
})
