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
            <p class="truncate text-sm font-semibold text-ink">External adapters</p>
            <p class="truncate text-xs text-muted">Approved providers with encrypted credentials and health checks</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Integrations</p>
          <h1 class="font-serif text-3xl font-bold">Service adapters</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createAdapter">
            <h2 class="font-semibold">Configure adapter</h2>
            <input v-model="form.name" required placeholder="Adapter name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.adapter_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="(meta, type) in store.catalog?.adapter_types || {}" :key="type" :value="type">{{ meta.label }}</option>
            </select>
            <select v-model="form.provider" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="(meta, provider) in providersForType" :key="provider" :value="provider">{{ meta.label }}</option>
            </select>
            <input v-model="form.health_base_url" required placeholder="Health check base URL (https://...)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.api_key" required type="password" autocomplete="off" placeholder="Primary credential" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving">Save adapter</button>
          </form>

          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Configured adapters</h2>
            <ul class="space-y-2 text-sm">
              <li v-for="adapter in store.adapters" :key="adapter.id" class="rounded-md border border-line px-3 py-2">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <p class="font-medium">{{ adapter.name }}</p>
                    <p class="text-xs text-muted">{{ adapter.provider }} · {{ adapter.status }} · {{ adapter.adapter_type }}</p>
                  </div>
                  <div class="flex gap-2 text-xs">
                    <button v-if="adapter.status === 'draft' || adapter.status === 'tested'" type="button" class="text-brand" @click="store.testAdapter(adapter.id)">Test</button>
                    <button v-if="adapter.status === 'tested'" type="button" class="text-brand" @click="store.activateAdapter(adapter.id)">Activate</button>
                    <button v-if="adapter.status === 'active'" type="button" class="text-red-700" @click="store.disableAdapter(adapter.id)">Disable</button>
                  </div>
                </div>
              </li>
            </ul>
            <button type="button" class="mt-4 rounded-md border border-line px-3 py-1 text-xs" @click="store.processDue()">Process due operations</button>
            <pre v-if="store.processResult" class="mt-3 overflow-auto rounded-md bg-canvas p-3 text-xs">{{ store.processResult }}</pre>
          </section>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useExternalAdaptersStore } from '../stores/externalAdapters'

const store = useExternalAdaptersStore()
const drawerOpen = ref(false)

const form = reactive({
  name: '',
  adapter_type: 'email',
  provider: 'sendgrid',
  health_base_url: '',
  api_key: '',
})

const providersForType = computed(() => {
  const providers = store.catalog?.providers || {}
  return Object.fromEntries(
    Object.entries(providers).filter(([, meta]) => meta.adapter_type === form.adapter_type),
  )
})

async function createAdapter() {
  await store.createAdapter({
    name: form.name,
    adapter_type: form.adapter_type,
    provider: form.provider,
    environment: 'sandbox',
    credentials: { api_key: form.api_key },
    callback_urls: { health_base_url: form.health_base_url, invoke_url: 'https://api.example.test/invoke' },
    mappings: {},
    quotas: { per_minute: 120 },
    feature_flags: {},
  })
  form.name = ''
  form.api_key = ''
}

onMounted(async () => {
  await store.loadCatalog()
  await store.loadAdapters()
})
</script>
