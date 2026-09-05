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
            <p class="truncate text-sm font-semibold">{{ store.dashboard?.dashboard?.name || 'My dashboard' }}</p>
            <p class="truncate text-xs text-muted">Role-assigned widgets with live permissions</p>
          </div>
          <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="store.loading" @click="refresh">Refresh</button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">My workspace</p>
          <h1 class="font-serif text-3xl font-bold">Assigned dashboard</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-danger/35 bg-danger-soft p-4 text-sm text-danger-strong">{{ store.error }}</p>
        <p v-if="store.loading && !store.dashboard" class="text-sm text-muted">Loading dashboard…</p>
        <p v-else-if="store.dashboard && !store.dashboard.assigned" class="text-sm text-muted">No published dashboard is assigned to your roles yet.</p>

        <div v-if="store.dashboard?.assigned" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <article
            v-for="widget in store.widgets"
            :key="widget.key"
            class="rounded-md border border-line bg-white p-4 shadow-sm"
            :class="widget.state === 'failed' ? 'border-danger/40' : ''"
          >
            <div class="mb-2 flex items-center justify-between gap-2">
              <h2 class="font-semibold">{{ widget.title }}</h2>
              <span class="rounded-full px-2 py-0.5 text-xs font-medium capitalize" :class="stateClass(widget.state)">{{ widget.state }}</span>
            </div>
            <p class="mb-2 text-xs text-muted">{{ widget.definition }}</p>
            <p v-if="widget.freshness" class="text-xs text-muted">Freshness: <span class="capitalize">{{ widget.freshness }}</span></p>
            <p v-if="widget.error" class="mt-2 text-sm text-danger-strong">{{ widget.error }}</p>
            <p v-else-if="widget.data?.total !== undefined" class="mt-2 text-2xl font-semibold">{{ formatTotal(widget) }}</p>
            <ul v-if="widget.data?.series?.length" class="mt-3 space-y-1 text-xs">
              <li v-for="point in widget.data.series" :key="point.label" class="flex justify-between gap-3">
                <span class="text-muted">{{ point.label }}</span>
                <span class="font-medium">{{ point.value }}</span>
              </li>
            </ul>
          </article>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useMyComposableDashboardStore } from '../stores/myComposableDashboard'

const drawerOpen = ref(false)
const store = useMyComposableDashboardStore()

function stateClass(state) {
  if (state === 'ready') return 'bg-success-soft text-success'
  if (state === 'failed') return 'bg-danger-soft text-danger-strong'
  return 'bg-canvas text-muted'
}

function formatTotal(widget) {
  if (widget.metric === 'giving') {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format((widget.data.total || 0) / 100)
  }
  return widget.data.total
}

async function refresh() {
  await store.load()
}

onMounted(() => store.load())
</script>
