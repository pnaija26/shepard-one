<template>
  <div class="min-h-screen bg-canvas text-ink">
    <div v-if="drawerOpen" class="fixed inset-0 z-40 bg-ink/45 lg:hidden" aria-hidden="true" @click="drawerOpen = false"></div>

    <Sidebar v-model:drawer-open="drawerOpen" />

    <div class="lg:pl-60">
      <header class="sticky top-0 z-30 border-b border-line bg-white/95 backdrop-blur">
        <div class="flex min-h-18 items-center gap-3 px-4 sm:px-6 lg:px-8">
          <button type="button" class="grid size-11 shrink-0 place-items-center rounded-md border border-line text-ink hover:bg-canvas lg:hidden" @click="drawerOpen = true">
            <Menu :size="20" aria-hidden="true" />
          </button>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-ink">Outbound webhooks</p>
            <p class="truncate text-xs text-muted">Signed event delivery with retries, quarantine, and policy gates</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Integrations</p>
          <h1 class="font-serif text-3xl font-bold">Webhook subscriptions</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createSubscription">
            <h2 class="font-semibold">Create subscription</h2>
            <input v-model="form.name" required placeholder="Subscription name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.endpoint_url" required type="url" placeholder="https://example.com/webhooks/shepard" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <label v-for="(meta, eventType) in store.catalog?.event_types || {}" :key="eventType" class="flex items-start gap-2 text-sm">
              <input v-model="form.allowed_event_types" type="checkbox" :value="eventType" class="mt-1 rounded border-line" />
              <span>{{ meta.label }} <span v-if="meta.requires_sensitive_approval" class="text-xs text-muted">(sensitive)</span></span>
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="form.sensitive_payload_approved" type="checkbox" class="rounded border-line" />
              <span>Sensitive payload policy approved</span>
            </label>
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving || form.allowed_event_types.length === 0">Create</button>
            <p v-if="store.latestSecret" class="rounded-md bg-canvas p-3 text-xs">Signing secret shown once: {{ store.latestSecret }}</p>
          </form>

          <section class="rounded-md border border-line bg-white p-5">
            <div class="mb-3 flex items-center justify-between gap-3">
              <h2 class="font-semibold">Subscriptions</h2>
              <button type="button" class="text-xs text-brand" @click="store.loadSubscriptions()">Refresh</button>
            </div>
            <ul class="space-y-2 text-sm">
              <li v-for="subscription in store.subscriptions" :key="subscription.id" class="rounded-md border border-line px-3 py-2">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <p class="font-medium">{{ subscription.name }}</p>
                    <p class="text-xs text-muted">{{ subscription.status }} · {{ subscription.allowed_event_types?.join(', ') }}</p>
                  </div>
                  <div class="flex gap-2 text-xs">
                    <button v-if="subscription.status === 'draft'" type="button" class="text-brand" @click="store.verifySubscription(subscription.id)">Verify</button>
                    <button v-if="subscription.status !== 'revoked'" type="button" class="text-red-700" @click="store.revokeSubscription(subscription.id)">Revoke</button>
                  </div>
                </div>
              </li>
            </ul>
            <button type="button" class="mt-4 rounded-md border border-line px-3 py-1 text-xs" @click="store.processDue()">Process due deliveries</button>
            <pre v-if="store.processResult" class="mt-3 overflow-auto rounded-md bg-canvas p-3 text-xs">{{ store.processResult }}</pre>
          </section>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useOutboundWebhooksStore } from '../stores/outboundWebhooks'

const store = useOutboundWebhooksStore()
const drawerOpen = ref(false)

const form = reactive({
  name: '',
  endpoint_url: '',
  allowed_event_types: ['member.created'],
  sensitive_payload_approved: false,
})

async function createSubscription() {
  await store.createSubscription({ ...form })
  form.name = ''
  form.endpoint_url = ''
}

onMounted(async () => {
  await store.loadCatalog()
  await store.loadSubscriptions()
})
</script>
