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
            <p class="truncate text-sm font-semibold text-ink">Payment sources</p>
            <p class="truncate text-xs text-muted">Connect approved gateways without exposing secrets</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Giving</p>
          <h1 class="font-serif text-3xl font-bold">Payment integrations</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createSource">
            <h2 class="font-semibold">Configure source</h2>
            <input v-model="form.name" required placeholder="Name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.provider" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="p in providers" :key="p" :value="p">{{ p }}</option>
            </select>
            <select v-model="form.environment" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="sandbox">sandbox</option>
              <option value="live">live</option>
            </select>
            <select v-model="form.currency" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
            </select>
            <input v-model="form.branch_id" type="number" placeholder="Branch ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <div class="flex flex-wrap gap-3 text-sm">
              <label v-for="cat in categories" :key="cat" class="flex items-center gap-1.5">
                <input v-model="form.supported_categories" type="checkbox" :value="cat" class="rounded border-line" />
                {{ cat }}
              </label>
            </div>
            <input v-model="form.api_key" required type="password" autocomplete="off" placeholder="API key (stored encrypted)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.webhook_secret" required type="password" autocomplete="off" placeholder="Webhook secret (stored encrypted)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <p class="text-xs text-muted">Secrets are encrypted at rest and never returned in full after save.</p>
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving">Save source</button>
          </form>

          <section class="space-y-6">
            <div class="rounded-md border border-line bg-white p-5">
              <h2 class="mb-3 font-semibold">Sources</h2>
              <ul class="space-y-2 text-sm">
                <li v-for="item in store.sources" :key="item.id" class="rounded-md border border-line p-3">
                  <button type="button" class="w-full text-left" @click="openSource(item.id)">
                    <p class="font-medium">{{ item.reference }} · {{ item.name }}</p>
                    <p class="text-xs text-muted">
                      {{ item.provider }} · {{ item.environment }} · {{ item.status }}
                      · {{ item.enabled ? 'enabled' : 'disabled' }}
                    </p>
                  </button>
                </li>
              </ul>
            </div>

            <section v-if="store.selected" class="space-y-3 rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="font-semibold">{{ store.selected.name }}</h2>
              <p class="text-xs text-muted">
                {{ store.selected.provider }} · {{ store.selected.currency }} · key {{ store.selected.api_key_hint || '—' }}
                · webhook {{ store.selected.webhook_secret_hint || '—' }}
              </p>
              <p class="text-xs text-muted">Categories: {{ (store.selected.supported_categories || []).join(', ') }}</p>

              <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="test">Test connection</button>
                <button
                  type="button"
                  class="rounded-md bg-brand px-2 py-1 text-xs font-semibold text-white"
                  :disabled="store.saving || store.selected.enabled"
                  @click="enable"
                >
                  Enable
                </button>
                <button
                  type="button"
                  class="rounded-md border border-line px-2 py-1 text-xs"
                  :disabled="store.saving || !store.selected.enabled"
                  @click="disable"
                >
                  Disable
                </button>
              </div>

              <div v-if="store.testResult" class="rounded-md border border-line bg-canvas p-3 text-xs">
                <p class="font-medium">Test: {{ store.testResult.passed ? 'passed' : 'failed' }}</p>
                <pre class="mt-1 overflow-auto whitespace-pre-wrap">{{ JSON.stringify(store.testResult.details, null, 2) }}</pre>
              </div>

              <div class="border-t border-line pt-3">
                <h3 class="mb-2 font-medium">Recent contributions</h3>
                <p v-if="!store.contributions.length" class="text-xs text-muted">None yet</p>
                <p v-for="row in store.contributions" :key="row.id" class="text-xs text-muted">
                  {{ row.reference }} · {{ row.category }} · {{ row.amount_cents }} {{ row.currency }} · {{ row.status }}
                  <span v-if="row.payer_linked"> · member #{{ row.member_id }}</span>
                </p>
              </div>
            </section>
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
import { usePaymentSourcesStore } from '../stores/paymentSources'

const store = usePaymentSourcesStore()
const drawerOpen = ref(false)
const providers = ['stripe', 'paystack', 'flutterwave']
const currencies = ['USD', 'EUR', 'GBP', 'NGN', 'GHS', 'KES', 'ZAR', 'CAD', 'AUD']
const categories = ['tithe', 'offering', 'building_fund', 'missions', 'welfare', 'event', 'other']

const form = reactive({
  name: '',
  provider: 'stripe',
  environment: 'sandbox',
  currency: 'USD',
  branch_id: '',
  supported_categories: ['tithe', 'offering'],
  api_key: '',
  webhook_secret: '',
})

async function createSource() {
  if (!form.supported_categories.length) {
    store.error = 'Select at least one category'
    return
  }
  await store.create({
    name: form.name,
    provider: form.provider,
    environment: form.environment,
    currency: form.currency,
    branch_id: form.branch_id ? Number(form.branch_id) : null,
    supported_categories: [...form.supported_categories],
    api_key: form.api_key,
    webhook_secret: form.webhook_secret,
  })
  form.api_key = ''
  form.webhook_secret = ''
}

async function openSource(id) {
  await store.select(id)
}

async function test() {
  if (!store.selected) return
  await store.test(store.selected.id)
}

async function enable() {
  if (!store.selected) return
  await store.update(store.selected.id, { enabled: true })
}

async function disable() {
  if (!store.selected) return
  await store.update(store.selected.id, { enabled: false })
}

onMounted(() => {
  store.fetchSources()
})
</script>
