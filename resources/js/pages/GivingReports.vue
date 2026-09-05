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
            <p class="truncate text-sm font-semibold text-ink">Giving reports</p>
            <p class="truncate text-xs text-muted">Permission-scoped totals with minimized donor identity</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Giving</p>
          <h1 class="font-serif text-3xl font-bold">Reports</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>

        <form class="mb-6 grid gap-2 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="load">
          <input v-model="filters.from" type="date" class="rounded-md border border-line px-3 py-2 text-sm" />
          <input v-model="filters.to" type="date" class="rounded-md border border-line px-3 py-2 text-sm" />
          <select v-model="filters.category" class="rounded-md border border-line px-3 py-2 text-sm">
            <option value="">All categories</option>
            <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
          </select>
          <input v-model="filters.branch_id" type="number" placeholder="Branch ID" class="rounded-md border border-line px-3 py-2 text-sm" />
          <input v-model="filters.campaign_id" type="number" placeholder="Campaign ID" class="rounded-md border border-line px-3 py-2 text-sm" />
          <label class="flex items-center gap-2 text-sm">
            <input v-model="filters.include_identity" type="checkbox" class="rounded border-line" />
            Include donor identity (if permitted)
          </label>
          <button type="submit" class="rounded-md bg-brand px-3 py-2 text-sm font-semibold text-white">Run report</button>
        </form>

        <section v-if="store.report" class="space-y-4">
          <div class="rounded-md border border-line bg-white p-5 text-sm">
            <p class="font-semibold">Totals</p>
            <p class="mt-1 text-xs text-muted">
              Gross {{ store.report.totals?.gross_cents }} · adjustments {{ store.report.totals?.adjustment_delta_cents }} ·
              net {{ store.report.totals?.net_cents }} · {{ store.report.totals?.count }} records
            </p>
            <p class="mt-2 text-xs text-muted">{{ store.report.policy?.note }} · identity: {{ store.report.policy?.donor_identity }}</p>
            <pre class="mt-3 overflow-auto text-xs text-muted">{{ JSON.stringify(store.report.totals?.by_category, null, 2) }}</pre>
          </div>

          <div class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Records</h2>
            <ul class="space-y-2 text-sm">
              <li v-for="row in store.report.records || []" :key="row.id" class="rounded-md border border-line p-3">
                <p class="font-medium">{{ row.reference }} · {{ row.amount_cents }} {{ row.currency }}</p>
                <p class="text-xs text-muted">
                  {{ row.category }} · {{ row.reconciliation_status }} ·
                  <span v-if="store.report.identity_included">{{ row.member_name || ('#' + row.member_id) }}</span>
                  <span v-else>{{ row.donor }}</span>
                </p>
              </li>
            </ul>
          </div>
        </section>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useGivingStore } from '../stores/giving'

const store = useGivingStore()
const drawerOpen = ref(false)
const categories = ['tithe', 'offering', 'building_fund', 'missions', 'welfare', 'event', 'other']

const filters = reactive({
  from: new Date(Date.now() - 365 * 86400000).toISOString().slice(0, 10),
  to: new Date().toISOString().slice(0, 10),
  category: '',
  branch_id: '',
  campaign_id: '',
  include_identity: false,
})

async function load() {
  await store.fetchReport({
    from: filters.from,
    to: filters.to,
    category: filters.category || undefined,
    branch_id: filters.branch_id ? Number(filters.branch_id) : undefined,
    campaign_id: filters.campaign_id ? Number(filters.campaign_id) : undefined,
    include_identity: filters.include_identity ? 1 : 0,
  })
}

onMounted(load)
</script>
