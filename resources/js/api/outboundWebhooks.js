import api from './client'

export function fetchOutboundWebhookCatalog() {
  return api.get('/outbound-webhooks/catalog')
}

export function listOutboundWebhookSubscriptions() {
  return api.get('/outbound-webhooks/subscriptions')
}

export function createOutboundWebhookSubscription(payload) {
  return api.post('/outbound-webhooks/subscriptions', payload)
}

export function verifyOutboundWebhookSubscription(id) {
  return api.post(`/outbound-webhooks/subscriptions/${id}/verify`)
}

export function revokeOutboundWebhookSubscription(id) {
  return api.post(`/outbound-webhooks/subscriptions/${id}/revoke`)
}

export function dispatchOutboundWebhookEvent(payload) {
  return api.post('/outbound-webhooks/dispatch', payload)
}

export function processDueOutboundWebhooks() {
  return api.post('/outbound-webhooks/process-due')
}
