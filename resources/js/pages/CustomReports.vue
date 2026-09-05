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
            <p class="truncate text-sm font-semibold text-ink">Custom reports</p>
            <p class="truncate text-xs text-muted">Design, preview, and run permission-safe reports</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Decisions</p>
          <h1 class="font-serif text-3xl font-bold">Custom report designer</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createReport">
            <h2 class="font-semibold">Create report</h2>
            <input v-model="form.name" required placeholder="Report name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model.number="form.branch_id" type="number" placeholder="Branch ID (optional)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.data_source" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="(meta, key) in store.catalog?.data_sources || {}" :key="key" :value="key">{{ meta.label }}</option>
            </select>
            <textarea v-model="form.definitionJson" rows="12" class="w-full rounded-md border border-line px-3 py-2 font-mono text-xs" />
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving">Create draft</button>
          </form>

          <section class="space-y-4 rounded-md border border-line bg-white p-5">
            <h2 class="font-semibold">Draft actions</h2>
            <p v-if="!store.selected" class="text-sm text-muted">Create or select a report to validate, preview, publish, or run.</p>
            <template v-else>
              <p class="text-sm"><span class="font-medium">{{ store.selected.name }}</span> — v{{ store.selected.draft_version ?? store.selected.current_version }} · {{ store.selected.status }}</p>
              <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" @click="validateReport">Validate</button>
                <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" @click="previewReport">Preview</button>
                <button type="button" class="rounded-md bg-brand px-3 py-2 text-xs font-semibold text-white" :disabled="store.saving" @click="publishReport">Publish</button>
                <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="store.loading || store.selected.status !== 'published'" @click="runReport">Run published</button>
                <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="store.selected.status !== 'published' || exportStore.loading" @click="exportPublished">Export CSV</button>
              </div>
              <div v-if="store.validation" class="rounded-md border border-line bg-canvas p-3 text-sm">
                <p class="font-medium">{{ store.validation.valid ? 'Valid definition' : 'Validation issues' }}</p>
                <ul v-if="store.validation.errors?.length" class="mt-2 list-disc pl-5 text-red-700">
                  <li v-for="(issue, index) in store.validation.errors" :key="index">{{ issue.message }}</li>
                </ul>
              </div>
            </template>
          </section>
        </div>

        <section v-if="store.preview" class="mt-6 rounded-md border border-line bg-white p-5">
          <h2 class="mb-3 font-semibold">Preview</h2>
          <p class="mb-3 text-xs text-muted">{{ store.preview.result?.row_count }} rows · {{ store.preview.result?.state }}</p>
          <pre class="overflow-auto rounded-md bg-canvas p-3 text-xs">{{ JSON.stringify(store.preview.result?.rows?.slice(0, 10), null, 2) }}</pre>
        </section>

        <section v-if="store.runResult" class="mt-6 rounded-md border border-line bg-white p-5">
          <h2 class="mb-3 font-semibold">Run result</h2>
          <p class="mb-3 text-xs text-muted">v{{ store.runResult.version }} · {{ store.runResult.row_count }} rows</p>
          <pre class="overflow-auto rounded-md bg-canvas p-3 text-xs">{{ JSON.stringify(store.runResult.rows?.slice(0, 10), null, 2) }}</pre>
        </section>

        <section class="mt-6 rounded-md border border-line bg-white p-5">
          <h2 class="mb-3 font-semibold">Saved reports</h2>
          <ul class="space-y-2 text-sm">
            <li v-for="report in store.reports" :key="report.id" class="flex items-center justify-between rounded-md border border-line px-3 py-2">
              <button type="button" class="text-left font-medium hover:text-brand" @click="selectReport(report)">{{ report.name }}</button>
              <span class="text-xs capitalize text-muted">{{ report.status }}</span>
            </li>
          </ul>
        </section>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useCustomReportsStore } from '../stores/customReports'
import { useReportExportsStore } from '../stores/reportExports'

const store = useCustomReportsStore()
const exportStore = useReportExportsStore()
const drawerOpen = ref(false)

const defaultDefinition = {
  data_source: 'members',
  fields: ['lifecycle_stage'],
  filters: [{ type: 'membership_stage', value: 'member' }],
  group_by: ['lifecycle_stage'],
  sort: [{ field: 'lifecycle_stage', direction: 'asc' }],
  calculations: [{ type: 'count', alias: 'total' }],
  joins: [],
}

const form = reactive({
  name: '',
  branch_id: '',
  data_source: 'members',
  definitionJson: JSON.stringify(defaultDefinition, null, 2),
})

function buildDefinition() {
  const parsed = JSON.parse(form.definitionJson)
  parsed.data_source = form.data_source

  return parsed
}

async function createReport() {
  const report = await store.createReport({
    name: form.name,
    branch_id: form.branch_id ? Number(form.branch_id) : undefined,
    definition: buildDefinition(),
  })
  store.selected = report
}

async function validateReport() {
  if (!store.selected) return
  await store.validateReport(store.selected.id)
}

async function previewReport() {
  if (!store.selected) return
  await store.previewReport(store.selected.id)
}

async function publishReport() {
  if (!store.selected) return
  await store.publishReport(store.selected.id)
}

async function runReport() {
  if (!store.selected) return
  await store.runReport(store.selected.id)
}

async function exportPublished() {
  if (!store.selected) return
  const result = await exportStore.requestExport({
    report_type: 'custom',
    custom_report_id: store.selected.id,
    format: 'csv',
    classification: 'internal',
  })
  if (result?.download?.token && result.status === 'completed') {
    await exportStore.downloadExport(result.reference, result.download.token)
  }
}

function selectReport(report) {
  store.selected = report
  form.definitionJson = JSON.stringify(report.definition ?? defaultDefinition, null, 2)
  form.data_source = report.definition?.data_source ?? 'members'
}

onMounted(async () => {
  await Promise.all([store.loadCatalog(), store.loadReports(), exportStore.loadCatalog()])
})
</script>
