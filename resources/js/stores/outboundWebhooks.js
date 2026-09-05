import { defineStore } from 'pinia'
import * as outboundWebhooksApi from '../api/outboundWebhooks'
import { extractApiError } from '../api/client'

export const useOutboundWebhooksStore = defineStore('outboundWebhooks', {
  state: () => ({
    catalog: null,
    subscriptions: [],
    latestSecret: null,
    processResult: null,
    saving: false,
    error: null,
  }),

  actions: {
    async loadCatalog() {
      const { data } = await outboundWebhooksApi.fetchOutboundWebhookCatalog()
      this.catalog = data.data
    },

    async loadSubscriptions() {
      const { data } = await outboundWebhooksApi.listOutboundWebhookSubscriptions()
      this.subscriptions = data.data ?? []
    },

    async createSubscription(payload) {
      this.saving = true
      this.error = null
      try {
        const { data } = await outboundWebhooksApi.createOutboundWebhookSubscription(payload)
        this.latestSecret = data.data.signing_secret
        await this.loadSubscriptions()
        return data.data
      } catch (error) {
        this.error = extractApiError(error, 'Unable to create webhook subscription.')
        throw error
      } finally {
        this.saving = false
      }
    },

    async verifySubscription(id) {
      await outboundWebhooksApi.verifyOutboundWebhookSubscription(id)
      await this.loadSubscriptions()
    },

    async revokeSubscription(id) {
      await outboundWebhooksApi.revokeOutboundWebhookSubscription(id)
      await this.loadSubscriptions()
    },

    async processDue() {
      const { data } = await outboundWebhooksApi.processDueOutboundWebhooks()
      this.processResult = data.data
      return data.data
    },
  },
})
