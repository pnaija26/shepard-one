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
            <p class="truncate text-sm font-semibold">{{ dashboardStore.dashboard?.team?.name || 'Team leader' }}</p>
            <p class="truncate text-xs text-muted">Roster, attendance, reports, and follow-ups</p>
          </div>
          <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="dashboardStore.loading" @click="refreshDashboard">Refresh</button>
        </div>
      </header>

      <main class="px-4 py-6 pb-24 sm:px-6 lg:px-8 lg:py-8 lg:pb-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Team leader</p>
          <h1 class="font-serif text-3xl font-bold">Team dashboard</h1>
        </section>

        <p v-if="dashboardStore.error" class="mb-4 rounded-md border border-danger/35 bg-danger-soft p-4 text-sm text-danger-strong" role="alert">
          {{ dashboardStore.error }}
          <button v-if="dashboardStore.conflict" type="button" class="mt-3 block rounded-md bg-brand px-3 py-2 text-xs font-semibold text-white" @click="resolveConflict">Load latest version</button>
        </p>

        <div class="mb-4 flex flex-wrap items-center gap-2">
          <label class="text-sm font-medium" for="team-select">Team</label>
          <select id="team-select" v-model="selectedTeam" class="min-h-11 rounded-md border border-line px-3 py-2 text-sm" @change="loadDashboard">
            <option v-for="team in dashboardStore.teams" :key="team.id" :value="team.id">{{ team.name }}</option>
          </select>
        </div>

        <p v-if="dashboardStore.loading && !dashboardStore.dashboard" class="text-sm text-muted" role="status" aria-live="polite">Loading dashboard…</p>

        <section v-if="dashboardStore.priorityActions.length" class="mb-6" aria-labelledby="priority-heading">
          <h2 id="priority-heading" class="mb-3 text-sm font-semibold">Priority actions</h2>
          <ul class="space-y-3">
            <li v-for="action in dashboardStore.priorityActions" :key="action.type + action.title" class="rounded-md border border-line bg-white p-4 shadow-sm">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ action.urgency_label }}</p>
                  <p class="mt-1 font-semibold">{{ action.title }}</p>
                  <p class="mt-1 text-sm text-muted">{{ action.detail }}</p>
                </div>
                <a :href="action.path" class="shrink-0 rounded-md bg-brand px-3 py-2 text-xs font-semibold text-white">Open</a>
              </div>
            </li>
          </ul>
        </section>

        <div v-if="dashboardStore.dashboard" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <section
            v-for="(widget, key) in visibleWidgets"
            :key="key"
            class="rounded-md border border-line bg-white p-4 shadow-sm"
            :aria-label="`${key} widget`"
          >
            <div class="mb-2 flex items-center justify-between gap-2">
              <h2 class="font-semibold capitalize">{{ widgetLabel(key) }}</h2>
              <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="stateClass(widget.state)" :aria-label="`Widget state: ${widget.state}`">{{ stateLabel(widget.state) }}</span>
            </div>
            <dl class="space-y-1 text-sm">
              <div v-for="(value, metric) in widgetMetrics(widget)" :key="metric" class="flex justify-between gap-3">
                <dt class="text-muted">{{ formatMetric(metric) }}</dt>
                <dd class="font-medium">{{ value }}</dd>
              </div>
            </dl>
            <button
              v-if="widget.drill_down && widget.state !== 'unauthorized'"
              type="button"
              class="mt-3 rounded-md border border-line px-2 py-1 text-xs"
              :disabled="dashboardStore.drillLoading"
              @click="openDrillDown(widget.drill_down, drillParams(key, widget))"
            >
              View details
            </button>
          </section>
        </div>

        <section v-if="dashboardStore.drillDown" class="mt-6 rounded-md border border-line bg-white p-5" aria-live="polite">
          <h2 class="mb-3 font-semibold capitalize">Details: {{ dashboardStore.drillDown.widget }}</h2>
          <p v-if="dashboardStore.drillLoading" class="text-sm text-muted">Loading records…</p>
          <ul v-else class="space-y-2 text-sm">
            <li v-for="(record, index) in dashboardStore.drillDown.records" :key="index" class="rounded-md border border-line p-3">
              <pre class="overflow-auto text-xs">{{ record }}</pre>
            </li>
          </ul>
        </section>
      </main>

      <nav class="fixed inset-x-0 bottom-0 z-20 border-t border-line bg-white/95 px-2 py-2 backdrop-blur lg:hidden" aria-label="Team leader navigation">
        <div class="mx-auto grid max-w-lg grid-cols-4 gap-1 text-center text-[11px]">
          <a href="/team-dashboard" class="rounded-md px-2 py-2 font-semibold text-brand" aria-current="page">Team</a>
          <a href="/team-rosters" class="rounded-md px-2 py-2 text-muted hover:text-ink">Rosters</a>
          <a href="/team-reports" class="rounded-md px-2 py-2 text-muted hover:text-ink">Reports</a>
          <a href="/follow-ups" class="rounded-md px-2 py-2 text-muted hover:text-ink">Follow-ups</a>
        </div>
      </nav>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useTeamDashboardStore } from '../stores/teamDashboard'

const drawerOpen = ref(false)
const dashboardStore = useTeamDashboardStore()
const selectedTeam = ref(null)

const visibleWidgets = computed(() => {
  const widgets = dashboardStore.dashboard?.widgets ?? {}
  return Object.fromEntries(Object.entries(widgets).filter(([, widget]) => widget.state !== 'unauthorized'))
})

function widgetLabel(key) {
  const labels = {
    follow_ups: 'Follow-ups',
    new_members: 'New members',
  }
  return labels[key] || key.replace(/_/g, ' ')
}

function formatMetric(key) {
  return key.replace(/_/g, ' ')
}

function stateClass(state) {
  if (state === 'ready') return 'bg-success-soft text-success'
  if (state === 'stale') return 'bg-warning-soft text-warning-ink'
  if (state === 'empty') return 'bg-canvas text-muted'
  return 'bg-canvas text-muted'
}

function stateLabel(state) {
  const labels = { ready: 'Ready', empty: 'Empty', stale: 'Needs refresh', unauthorized: 'Unavailable' }
  return labels[state] || state
}

function widgetMetrics(widget) {
  const metrics = { ...widget }
  delete metrics.state
  delete metrics.drill_down
  delete metrics.label
  return metrics
}

function drillParams(key, widget) {
  if (key === 'reports' || key === 'follow_ups') return { status: 'draft' }
  if (key === 'assignments') return { status: 'pending' }
  if (key === 'attendance' && widget.uncaptured_past_occurrences > 0) return { scope: 'uncaptured' }
  if ((key === 'tasks' || key === 'follow_ups') && widget.overdue_tasks > 0) return { scope: 'overdue' }
  return {}
}

async function loadDashboard() {
  if (!selectedTeam.value) return
  await dashboardStore.loadDashboard(selectedTeam.value)
}

async function refreshDashboard() {
  try {
    await dashboardStore.syncAfterAction('manual_refresh', dashboardStore.version)
  } catch {
    /* error surfaced in store */
  }
}

async function resolveConflict() {
  await dashboardStore.resolveConflict()
}

async function openDrillDown(widget, params) {
  await dashboardStore.openDrillDown(widget, params)
}

onMounted(async () => {
  await dashboardStore.loadTeams()
  if (dashboardStore.teams.length > 0) {
    selectedTeam.value = dashboardStore.teams[0].id
    await loadDashboard()
  }
})

watch(() => dashboardStore.selectedTeamId, (teamId) => {
  if (teamId) selectedTeam.value = teamId
})
</script>
