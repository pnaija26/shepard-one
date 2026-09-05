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
            <p class="truncate text-sm font-semibold text-ink">API platform</p>
            <p class="truncate text-xs text-muted">Versioned contracts, machine principals, and integration clients</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Integrations</p>
          <h1 class="font-serif text-3xl font-bold">Protected REST APIs</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createClient">
            <h2 class="font-semibold">Create machine principal</h2>
            <input v-model="form.name" required placeholder="Client name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <label v-for="(label, scope) in store.catalog?.scopes || {}" :key="scope" class="flex items-center gap-2 text-sm">
              <input v-model="form.allowed_scopes" type="checkbox" :value="scope" class="rounded border-line" />
              <span>{{ label }}</span>
            </label>
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving || form.allowed_scopes.length === 0">Create client</button>
            <p v-if="store.latestSecret" class="rounded-md bg-canvas p-3 text-xs">Secret shown once: {{ store.latestSecret }}</p>
          </form>

          <section class="rounded-md border border-line bg-white p-5">
            <div class="mb-3 flex items-center justify-between gap-3">
              <h2 class="font-semibold">API clients</h2>
              <button type="button" class="text-xs text-brand" @click="refresh">Refresh</button>
            </div>
            <ul class="space-y-2 text-sm">
              <li v-for="client in store.clients" :key="client.id" class="rounded-md border border-line px-3 py-2">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <p class="font-medium">{{ client.name }}</p>
                    <p class="text-xs text-muted">{{ client.client_id }} · {{ client.status }}</p>
                  </div>
                  <button v-if="client.status === 'active'" type="button" class="text-xs text-red-700" @click="store.revokeClient(client.id)">Revoke</button>
                </div>
              </li>
            </ul>
          </section>
        </div>

        <section class="mt-6 rounded-md border border-line bg-white p-5">
          <div class="mb-3 flex items-center justify-between gap-3">
            <h2 class="font-semibold">Executable contract</h2>
            <button type="button" class="rounded-md border border-line px-3 py-1 text-xs" @click="validateContract">Validate routes</button>
          </div>
          <p v-if="store.validation" class="mb-3 text-sm" :class="store.validation.valid ? 'text-green-700' : 'text-red-700'">
            {{ store.validation.valid ? 'Contract matches registered routes.' : `${store.validation.issue_count} contract drift issue(s) detected.` }}
          </p>
          <pre class="overflow-auto rounded-md bg-canvas p-3 text-xs">{{ store.contract?.endpoints || [] }}</pre>
        </section>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useApiPlatformStore } from '../stores/apiPlatform'

const store = useApiPlatformStore()
const drawerOpen = ref(false)

const form = reactive({
  name: '',
  allowed_scopes: ['members.read'],
})

async function createClient() {
  await store.createClient({
    name: form.name,
    allowed_scopes: form.allowed_scopes,
  })
  form.name = ''
}

async function validateContract() {
  await store.validateContract()
}

async function refresh() {
  await store.loadClients()
  await store.loadContract()
}

onMounted(async () => {
  await store.loadCatalog()
  await store.loadContract()
  await store.loadClients()
})
</script>
