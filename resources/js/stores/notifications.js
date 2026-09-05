import { defineStore } from 'pinia'
import notificationsApi from '../api/notifications'
import { extractApiError } from '../api/client'

export const useNotificationsStore = defineStore('notifications', {
  state: () => ({
    items: [],
    unreadCount: 0,
    categories: {},
    selected: null,
    loading: false,
    saving: false,
    error: null,
    empty: false,
  }),

  actions: {
    async fetchInbox(params = {}) {
      this.loading = true
      this.error = null
      try {
        const response = await notificationsApi.list(params)
        this.items = response.data?.data ?? []
        this.unreadCount = response.data?.meta?.unread_count ?? 0
        this.categories = response.data?.meta?.categories ?? {}
        this.empty = this.items.length === 0
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load notifications')
        this.items = []
        this.empty = false
      } finally {
        this.loading = false
      }
    },

    async fetchSummary() {
      try {
        const response = await notificationsApi.summary()
        this.unreadCount = response.data?.data?.unread_count ?? 0
        this.categories = response.data?.data?.categories ?? this.categories
      } catch {
        // non-blocking for badge
      }
    },

    async markRead(id) {
      this.saving = true
      this.error = null
      try {
        const response = await notificationsApi.markRead(id)
        this.patchItem(response.data?.data)
        this.unreadCount = response.data?.meta?.unread_count ?? this.unreadCount
      } catch (error) {
        this.error = extractApiError(error, 'Unable to mark read')
        throw error
      } finally {
        this.saving = false
      }
    },

    async markUnread(id) {
      this.saving = true
      this.error = null
      try {
        const response = await notificationsApi.markUnread(id)
        this.patchItem(response.data?.data)
        this.unreadCount = response.data?.meta?.unread_count ?? this.unreadCount
      } catch (error) {
        this.error = extractApiError(error, 'Unable to mark unread')
        throw error
      } finally {
        this.saving = false
      }
    },

    async archive(id) {
      this.saving = true
      this.error = null
      try {
        await notificationsApi.archive(id)
        this.items = this.items.filter((item) => item.id !== id)
        this.empty = this.items.length === 0
        await this.fetchSummary()
      } catch (error) {
        this.error = extractApiError(error, 'Unable to archive')
        throw error
      } finally {
        this.saving = false
      }
    },

    async markAllRead() {
      this.saving = true
      this.error = null
      try {
        const response = await notificationsApi.markAllRead()
        this.unreadCount = response.data?.data?.unread_count ?? 0
        this.items = this.items.map((item) => ({ ...item, is_read: true, read_at: item.read_at || new Date().toISOString() }))
      } catch (error) {
        this.error = extractApiError(error, 'Unable to mark all read')
        throw error
      } finally {
        this.saving = false
      }
    },

    async open(id) {
      this.saving = true
      this.error = null
      try {
        const response = await notificationsApi.open(id)
        await this.fetchInbox()
        return response.data?.data ?? null
      } catch (error) {
        this.error = extractApiError(error, 'Unable to open notification')
        throw error
      } finally {
        this.saving = false
      }
    },

    patchItem(updated) {
      if (!updated) return
      this.items = this.items.map((item) => (item.id === updated.id ? updated : item))
      this.selected = updated
    },
  },
})
