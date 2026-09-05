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
            <p class="truncate text-sm font-semibold">{{ dashboardStore.dashboard?.branch?.name || 'Branch administrator' }}</p>
            <p class="truncate text-xs text-muted">Growth, engagement, care, and operations</p>
          </div>
          <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="dashboardStore.loading" @click="applyPeriod">Apply period</button>
        </div>
      </header>

      <main class="px-4 py-6 pb-24 sm:px-6 lg:px-8 lg:py-8 lg:pb-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Branch administrator</p>
          <h1 class="font-serif text-3xl font-bold">Branch dashboard</h1>
          <p v-if="dashboardStore.period" class="mt-2 text-sm text-muted">
            Period {{ dashboardStore.period.from }} to {{ dashboardStore.period.to }}
            <span v-if="dashboardStore.dashboard?.generated_at" class="block text-xs">
              Data as of {{ formatTimestamp(dashboardStore.dashboard.generated_at) }}
            </span>
          </p>
        </section>

        <p v-if="dashboardStore.error" class="mb-4 rounded-md border border-danger/35 bg-danger-soft p-4 text-sm text-danger-strong" role="alert">
          {{ dashboardStore.error }}
        </p>

        <div class="mb-4 flex flex-wrap items-end gap-3">
          <div>
            <label class="text-sm font-medium" for="branch-select">Branch</label>
            <select id="branch-select" v-model="selectedBranch" class="mt-1 block min-h-11 rounded-md border border-line px-3 py-2 text-sm" @change="loadDashboard">
              <option v-for="branch in dashboardStore.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
            </select>
          </div>
          <div>
            <label class="text-sm font-medium" for="period-from">From</label>
            <input id="period-from" v-model="dashboardStore.periodFrom" type="date" class="mt-1 block min-h-11 rounded-md border border-line px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="text-sm font-medium" for="period-to">To</label>
            <input id="period-to" v-model="dashboardStore.periodTo" type="date" class="mt-1 block min-h-11 rounded-md border border-line px-3 py-2 text-sm" />
          </div>
        </div>

        <p v-if="dashboardStore.loading && !dashboardStore.dashboard" class="text-sm text-muted" role="status" aria-live="polite">Loading dashboard…</p>

        <div v-if="dashboardStore.dashboard" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <section
            v-for="(metric, key) in visibleMetrics"
            :key="key"
            class="rounded-md border border-line bg-white p-4 shadow-sm"
            :aria-label="`${metricLabel(key)} metric`"
          >
            <div class="mb-2 flex items-center justify-between gap-2">
              <h2 class="font-semibold">{{ metricLabel(key) }}</h2>
              <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="stateClass(metric.state)" :aria-label="`Metric state: ${metric.state}`">{{ stateLabel(metric.state) }}</span>
            </div>
            <dl class="space-y-1 text-sm">
              <div v-if="metric.total !== undefined" class="flex justify-between gap-3">
                <dt class="text-muted">Total</dt>
                <dd class="font-medium">{{ formatValue(key, metric.total) }}</dd>
              </div>
              <div v-if="metric.previous_total !== undefined" class="flex justify-between gap-3">
                <dt class="text-muted">Previous period</dt>
                <dd class="font-medium">{{ formatValue(key, metric.previous_total) }}</dd>
              </div>
              <div v-if="metric.trend" class="flex justify-between gap-3">
                <dt class="text-muted">Trend</dt>
                <dd class="font-medium capitalize">{{ metric.trend }}</dd>
              </div>
              <div v-if="metric.freshness" class="flex justify-between gap-3">
                <dt class="text-muted">Freshness</dt>
                <dd class="font-medium capitalize">{{ metric.freshness }}</dd>
              </div>
              <div v-for="(value, summaryKey) in metric.summary || {}" :key="summaryKey" class="flex justify-between gap-3">
                <dt class="text-muted">{{ formatMetric(summaryKey) }}</dt>
                <dd class="font-medium">{{ formatSummaryValue(summaryKey, value) }}</dd>
              </div>
            </dl>
            <button
              v-if="metric.drill_down && metric.state !== 'unauthorized'"
              type="button"
              class="mt-3 rounded-md border border-line px-2 py-1 text-xs"
              :disabled="dashboardStore.drillLoading"
              @click="openDrillDown(metric.drill_down)"
            >
              View details
            </button>
          </section>
        </div>

        <section v-if="dashboardStore.drillDown" class="mt-6 rounded-md border border-line bg-white p-5" aria-live="polite">
          <h2 class="mb-3 font-semibold">Details: {{ metricLabel(dashboardStore.drillDown.metric) }}</h2>
          <p class="mb-3 text-sm text-muted">
            Showing {{ dashboardStore.drillDown.record_count }} of {{ dashboardStore.drillDown.widget_total ?? '—' }} records
          </p>
          <p v-if="dashboardStore.drillLoading" class="text-sm text-muted">Loading records…</p>
          <ul v-else class="space-y-2 text-sm">
            <li v-for="(record, index) in dashboardStore.drillDown.records" :key="index" class="rounded-md border border-line p-3">
              <pre class="overflow-auto text-xs">{{ record }}</pre>
            </li>
          </ul>
        </section>
      </main>

      <nav class="fixed inset-x-0 bottom-0 z-20 border-t border-line bg-white/95 px-2 py-2 backdrop-blur lg:hidden" aria-label="Branch administrator navigation">
        <div class="mx-auto grid max-w-lg grid-cols-4 gap-1 text-center text-[11px]">
          <a href="/branch-dashboard" class="rounded-md px-2 py-2 font-semibold text-brand" aria-current="page">Branch</a>
          <a href="/members" class="rounded-md px-2 py-2 text-muted hover:text-ink">Members</a>
          <a href="/follow-ups" class="rounded-md px-2 py-2 text-muted hover:text-ink">Follow-ups</a>
          <a href="/giving-reports" class="rounded-md px-2 py-2 text-muted hover:text-ink">Giving</a>
        </div>
      </nav>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useBranchDashboardStore } from '../stores/branchDashboard'

const drawerOpen = ref(false)
const dashboardStore = useBranchDashboardStore()
const selectedBranch = ref(null)

const visibleMetrics = computed(() => {
  const metrics = dashboardStore.dashboard?.metrics ?? {}
  return Object.fromEntries(Object.entries(metrics).filter(([, metric]) => metric.state !== 'unauthorized'))
})

function metricLabel(key) {
  const labels = {
    follow_up: 'Follow-up',
    giving: 'Giving',
  }
  return labels[key] || key.replace(/_/g, ' ')
}

function formatMetric(key) {
  return key.replace(/_/g, ' ')
}

function formatValue(key, value) {
  if (key === 'giving') {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(value / 100)
  }
  return value
}

function formatSummaryValue(key, value) {
  if (key === 'total_cents') {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(value / 100)
  }
  if (typeof value === 'boolean') {
    return value ? 'Yes' : 'No'
  }
  return value
}

function formatTimestamp(value) {
  return new Date(value).toLocaleString()
}

function stateClass(state) {
  if (state === 'ready') return 'bg-success-soft text-success'
  if (state === 'empty') return 'bg-canvas text-muted'
  return 'bg-canvas text-muted'
}

function stateLabel(state) {
  const labels = { ready: 'Ready', empty: 'Empty', unauthorized: 'Unavailable' }
  return labels[state] || state
}

async function loadDashboard() {
  if (!selectedBranch.value) return
  await dashboardStore.loadDashboard(selectedBranch.value)
}

async function applyPeriod() {
  await loadDashboard()
}

async function openDrillDown(metric) {
  await dashboardStore.openDrillDown(metric)
}

onMounted(async () => {
  await dashboardStore.loadBranches()
  if (dashboardStore.branches.length > 0) {
    selectedBranch.value = dashboardStore.branches[0].id
    await loadDashboard()
  }
})
</script>
