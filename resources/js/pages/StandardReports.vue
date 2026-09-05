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
            <p class="truncate text-sm font-semibold text-ink">Standard reports</p>
            <p class="truncate text-xs text-muted">Trusted operational and management reports</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Decisions</p>
          <h1 class="font-serif text-3xl font-bold">Standard church reports</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>

        <form class="mb-6 grid gap-3 rounded-md border border-line bg-white p-5 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="runReport">
          <label class="text-sm">
            <span class="mb-1 block font-medium">Report</span>
            <select v-model="filters.report_key" required class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="(meta, key) in store.catalog?.reports || {}" :key="key" :value="key">{{ meta.label }}</option>
            </select>
          </label>
          <label class="text-sm">
            <span class="mb-1 block font-medium">Period</span>
            <select v-model="filters.period_preset" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="(meta, key) in store.catalog?.period_presets || {}" :key="key" :value="key">{{ meta.label }}</option>
            </select>
          </label>
          <label class="text-sm">
            <span class="mb-1 block font-medium">From</span>
            <input v-model="filters.period_from" type="date" class="w-full rounded-md border border-line px-3 py-2 text-sm" :disabled="filters.period_preset !== 'custom'" />
          </label>
          <label class="text-sm">
            <span class="mb-1 block font-medium">To</span>
            <input v-model="filters.period_to" type="date" class="w-full rounded-md border border-line px-3 py-2 text-sm" :disabled="filters.period_preset !== 'custom'" />
          </label>
          <label class="text-sm sm:col-span-2">
            <span class="mb-1 block font-medium">Branch ID</span>
            <input v-model="filters.branch_id" type="number" placeholder="Optional branch filter" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
          </label>
          <div class="flex items-end gap-2">
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.loading">
              {{ store.loading ? 'Running…' : 'Run report' }}
            </button>
            <button type="button" class="rounded-md border border-line px-4 py-2 text-sm font-semibold" :disabled="!store.report || exportStore.loading" @click="exportReport">
              {{ exportStore.loading ? 'Exporting…' : 'Export CSV' }}
            </button>
          </div>
        </form>

        <section v-if="store.failure?.details" class="mb-6 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
          <p class="font-semibold">Report could not be completed</p>
          <p class="mt-1">{{ store.failure.details.support_hint }}</p>
          <p class="mt-2 text-xs">Reference: {{ store.failure.details.reference }}</p>
        </section>

        <p v-if="exportStore.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ exportStore.error }}</p>

        <section v-if="exportStore.lastExport" class="mb-6 rounded-md border border-line bg-white p-4 text-sm">
          <p class="font-semibold">Export {{ exportStore.lastExport.status }}</p>
          <p class="mt-1 text-xs text-muted">Reference {{ exportStore.lastExport.reference }} · {{ exportStore.lastExport.row_count }} rows</p>
          <button
            v-if="exportStore.lastExport.download?.token && exportStore.lastExport.status === 'completed'"
            type="button"
            class="mt-3 rounded-md border border-line px-3 py-2 text-xs font-semibold"
            @click="downloadExport"
          >
            Download export
          </button>
        </section>

        <section v-if="store.report" class="space-y-4">
          <div class="rounded-md border border-line bg-white p-5 text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <p class="font-semibold">{{ store.report.label }}</p>
                <p class="mt-1 text-xs text-muted">{{ store.report.definition }}</p>
              </div>
              <span class="rounded-full bg-canvas px-3 py-1 text-xs capitalize">{{ store.report.state }}</span>
            </div>
            <p class="mt-3 text-xs text-muted">
              {{ store.report.period?.from }} → {{ store.report.period?.to }}
              <span v-if="store.report.branch"> · {{ store.report.branch.name }}</span>
              · ref {{ store.report.reference }}
            </p>
            <ul v-if="store.report.limitations?.length" class="mt-3 list-disc pl-5 text-xs text-amber-800">
              <li v-for="(limitation, index) in store.report.limitations" :key="index">{{ limitation.message }}</li>
            </ul>
          </div>

          <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article v-for="section in store.report.sections" :key="section.key" class="rounded-md border border-line bg-white p-4">
              <div class="mb-2 flex items-center justify-between gap-2">
                <h3 class="font-semibold">{{ section.label }}</h3>
                <span class="rounded-full bg-canvas px-2 py-0.5 text-xs capitalize">{{ section.state }}</span>
              </div>
              <p class="mb-2 text-xs text-muted">{{ section.definition }}</p>
              <p v-if="section.value !== null && section.value !== undefined" class="text-2xl font-bold">{{ section.value }}</p>
              <p v-else class="text-sm text-muted">Not shown</p>
              <p class="mt-2 text-xs text-muted capitalize">Freshness: {{ section.freshness }}</p>
              <p v-if="section.limitation" class="mt-2 text-xs text-amber-800">{{ section.limitation }}</p>
            </article>
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
import { useStandardReportsStore } from '../stores/standardReports'
import { useReportExportsStore } from '../stores/reportExports'

const store = useStandardReportsStore()
const exportStore = useReportExportsStore()
const drawerOpen = ref(false)

const filters = reactive({
  report_key: 'membership',
  period_preset: 'monthly',
  period_from: '',
  period_to: '',
  branch_id: '',
})

async function runReport() {
  const params = {
    period_preset: filters.period_preset,
  }

  if (filters.period_preset === 'custom') {
    if (filters.period_from) params.period_from = filters.period_from
    if (filters.period_to) params.period_to = filters.period_to
  }

  if (filters.branch_id) {
    params.branch_id = filters.branch_id
  }

  await store.runReport(filters.report_key, params)
}

function buildExportFilters() {
  const params = { period_preset: filters.period_preset }
  if (filters.period_preset === 'custom') {
    if (filters.period_from) params.period_from = filters.period_from
    if (filters.period_to) params.period_to = filters.period_to
  }
  if (filters.branch_id) params.branch_id = filters.branch_id
  return params
}

async function exportReport() {
  if (!store.report) return
  const result = await exportStore.requestExport({
    report_type: 'standard',
    report_key: filters.report_key,
    format: 'csv',
    classification: 'internal',
    filters: buildExportFilters(),
  })
  if (result?.download?.token && result.status === 'completed') {
    await exportStore.downloadExport(result.reference, result.download.token)
  }
}

async function downloadExport() {
  const exp = exportStore.lastExport
  if (!exp?.download?.token) return
  await exportStore.downloadExport(exp.reference, exp.download.token, exp.metadata?.filename)
}

onMounted(async () => {
  await Promise.all([store.loadCatalog(), exportStore.loadCatalog()])
  if (store.catalog?.reports && !store.catalog.reports[filters.report_key]) {
    filters.report_key = Object.keys(store.catalog.reports)[0] ?? 'membership'
  }
})
</script>
