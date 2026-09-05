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
            <p class="truncate text-sm font-semibold text-ink">Audit Log</p>
            <p class="truncate text-xs text-muted">Security and business audit events</p>
          </div>
          <button
            type="button"
            class="rounded-md bg-brand px-3 py-2 text-xs font-semibold text-white hover:bg-brand-hover disabled:opacity-50"
            :disabled="auditStore.exporting"
            @click="exportEvents"
          >
            {{ auditStore.exporting ? 'Exporting…' : 'Export JSON' }}
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Story 1.8</p>
          <h1 class="font-serif text-3xl font-bold">Audit Events</h1>
          <p class="mt-1 text-sm text-muted">Search protected audit records within your authorized scope</p>
        </section>

        <p v-if="auditStore.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger" role="alert">
          {{ auditStore.error }}
        </p>

        <form class="mb-6 grid gap-3 rounded-md border border-line bg-white p-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="search">
          <div>
            <label class="text-xs font-medium text-muted">From</label>
            <input v-model="auditStore.filters.from" type="date" class="mt-1 block w-full rounded-md border border-line px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="text-xs font-medium text-muted">To</label>
            <input v-model="auditStore.filters.to" type="date" class="mt-1 block w-full rounded-md border border-line px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="text-xs font-medium text-muted">Action</label>
            <input v-model="auditStore.filters.action" type="text" placeholder="auth.login" class="mt-1 block w-full rounded-md border border-line px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="text-xs font-medium text-muted">Module</label>
            <input v-model="auditStore.filters.module" type="text" placeholder="auth" class="mt-1 block w-full rounded-md border border-line px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="text-xs font-medium text-muted">Actor ID</label>
            <input v-model="auditStore.filters.actor_id" type="number" class="mt-1 block w-full rounded-md border border-line px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="text-xs font-medium text-muted">Branch ID</label>
            <input v-model="auditStore.filters.branch_id" type="number" class="mt-1 block w-full rounded-md border border-line px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="text-xs font-medium text-muted">Category</label>
            <select v-model="auditStore.filters.category" class="mt-1 block w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="">All</option>
              <option value="security">Security</option>
              <option value="business">Business</option>
            </select>
          </div>
          <div class="flex items-end gap-2">
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-hover">Search</button>
            <button type="button" class="rounded-md border border-line px-4 py-2 text-sm font-semibold text-ink hover:bg-canvas" @click="reset">Reset</button>
          </div>
        </form>

        <div v-if="auditStore.loading" class="text-sm text-muted">Loading audit events…</div>

        <div v-else class="overflow-hidden rounded-md border border-line bg-white">
          <table class="min-w-full text-left text-sm">
            <thead class="border-b border-line bg-canvas/60 text-xs uppercase tracking-wide text-muted">
              <tr>
                <th class="px-4 py-3 font-semibold">When</th>
                <th class="px-4 py-3 font-semibold">Actor</th>
                <th class="px-4 py-3 font-semibold">Action</th>
                <th class="px-4 py-3 font-semibold">Module</th>
                <th class="px-4 py-3 font-semibold">Branch</th>
                <th class="px-4 py-3 font-semibold"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="auditStore.events.length === 0">
                <td colspan="6" class="px-4 py-6 text-center text-muted">No audit events match your filters.</td>
              </tr>
              <tr v-for="event in auditStore.events" :key="event.id" class="border-b border-line last:border-b-0">
                <td class="px-4 py-3 whitespace-nowrap text-xs text-muted">{{ formatDate(event.created_at) }}</td>
                <td class="px-4 py-3">
                  <p class="font-medium">{{ event.actor?.name ?? 'System' }}</p>
                  <p class="text-xs text-muted">{{ event.actor?.email ?? '—' }}</p>
                </td>
                <td class="px-4 py-3 font-mono text-xs">{{ event.action }}</td>
                <td class="px-4 py-3">{{ event.module ?? '—' }}</td>
                <td class="px-4 py-3">{{ event.branch?.name ?? '—' }}</td>
                <td class="px-4 py-3 text-right">
                  <button type="button" class="text-xs font-semibold text-brand hover:underline" @click="openDetail(event.id)">Details</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="auditStore.meta && auditStore.meta.last_page > 1" class="mt-4 flex items-center justify-between text-sm">
          <p class="text-muted">Page {{ auditStore.meta.current_page }} of {{ auditStore.meta.last_page }} ({{ auditStore.meta.total }} records)</p>
          <div class="flex gap-2">
            <button
              type="button"
              class="rounded-md border border-line px-3 py-1.5 disabled:opacity-50"
              :disabled="auditStore.meta.current_page <= 1"
              @click="changePage(auditStore.meta.current_page - 1)"
            >
              Previous
            </button>
            <button
              type="button"
              class="rounded-md border border-line px-3 py-1.5 disabled:opacity-50"
              :disabled="auditStore.meta.current_page >= auditStore.meta.last_page"
              @click="changePage(auditStore.meta.current_page + 1)"
            >
              Next
            </button>
          </div>
        </div>
      </main>
    </div>

    <div v-if="detailOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-ink/45 p-4" @click.self="detailOpen = false">
      <div class="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-md border border-line bg-white p-6 shadow-lg">
        <div class="mb-4 flex items-start justify-between gap-4">
          <div>
            <h2 class="font-serif text-xl font-bold">Event #{{ auditStore.selectedEvent?.id }}</h2>
            <p class="text-sm text-muted">{{ auditStore.selectedEvent?.action }}</p>
          </div>
          <button type="button" class="text-sm font-semibold text-muted hover:text-ink" @click="detailOpen = false">Close</button>
        </div>
        <pre class="overflow-x-auto rounded-md bg-canvas p-4 text-xs">{{ pretty(auditStore.selectedEvent) }}</pre>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useAuditStore } from '../stores/audit'

const auditStore = useAuditStore()
const drawerOpen = ref(false)
const detailOpen = ref(false)

const formatDate = (value) => {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}

const pretty = (value) => JSON.stringify(value, null, 2)

const search = async () => {
  await auditStore.fetchEvents(1)
}

const reset = async () => {
  auditStore.resetFilters()
  await auditStore.fetchEvents(1)
}

const changePage = async (page) => {
  await auditStore.fetchEvents(page)
}

const openDetail = async (id) => {
  await auditStore.fetchEvent(id)
  detailOpen.value = true
}

const exportEvents = async () => {
  await auditStore.exportEvents()
}

onMounted(async () => {
  await auditStore.fetchEvents()
})
</script>
