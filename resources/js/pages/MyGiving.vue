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
            <p class="truncate text-sm font-semibold text-ink">My giving</p>
            <p class="truncate text-xs text-muted">Personal contribution history and statements</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Giving</p>
          <h1 class="font-serif text-3xl font-bold">My history</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>

        <form class="mb-6 flex flex-wrap gap-2" @submit.prevent="load">
          <input v-model="from" type="date" class="rounded-md border border-line px-3 py-2 text-sm" />
          <input v-model="to" type="date" class="rounded-md border border-line px-3 py-2 text-sm" />
          <button type="submit" class="rounded-md border border-line px-3 py-2 text-sm font-semibold">Refresh</button>
          <button type="button" class="rounded-md bg-brand px-3 py-2 text-sm font-semibold text-white" :disabled="store.saving" @click="statement">
            Request statement
          </button>
        </form>

        <section v-if="store.history" class="mb-6 rounded-md border border-line bg-white p-5">
          <p class="text-sm text-muted">
            {{ store.history.period?.from }} → {{ store.history.period?.to }} ·
            {{ store.history.count }} gifts · {{ store.history.total_cents }} {{ store.history.currency }}
          </p>
          <ul class="mt-4 space-y-2 text-sm">
            <li v-for="row in store.history.items || []" :key="row.id" class="rounded-md border border-line p-3">
              <p class="font-medium">{{ row.reference }} · {{ row.amount_cents }} {{ row.currency }}</p>
              <p class="text-xs text-muted">{{ row.category }} · {{ row.occurred_at }}</p>
            </li>
          </ul>
        </section>

        <section v-if="store.statement" class="rounded-md border border-line bg-white p-5 text-sm">
          <h2 class="font-semibold">Statement {{ store.statement.reference }}</h2>
          <p class="text-xs text-muted">
            {{ store.statement.period_from }} → {{ store.statement.period_to }} ·
            total {{ store.statement.total_cents }} {{ store.statement.currency }}
          </p>
          <pre class="mt-3 overflow-auto text-xs text-muted">{{ JSON.stringify(store.statement.totals_by_category, null, 2) }}</pre>
        </section>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useGivingStore } from '../stores/giving'

const store = useGivingStore()
const drawerOpen = ref(false)
const from = ref(new Date(Date.now() - 365 * 86400000).toISOString().slice(0, 10))
const to = ref(new Date().toISOString().slice(0, 10))

async function load() {
  await store.fetchHistory({ from: from.value, to: to.value })
}

async function statement() {
  await store.requestStatement({ from: from.value, to: to.value })
}

onMounted(load)
</script>
