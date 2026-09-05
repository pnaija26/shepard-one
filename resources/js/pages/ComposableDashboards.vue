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
            <p class="truncate text-sm font-semibold">Dashboard composer</p>
            <p class="truncate text-xs text-muted">Configure role-specific widgets and publish versions</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Decisions</p>
          <h1 class="font-serif text-3xl font-bold">Composable dashboards</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-danger/35 bg-danger-soft p-4 text-sm text-danger-strong">{{ store.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createDashboard">
            <h2 class="font-semibold">Create dashboard</h2>
            <input v-model="form.name" required placeholder="Dashboard name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model.number="form.branch_id" type="number" placeholder="Branch ID (optional)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.role_ids" placeholder="Role IDs (comma-separated)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <textarea v-model="form.widgetsJson" rows="10" class="w-full rounded-md border border-line px-3 py-2 font-mono text-xs" />
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving">Create draft</button>
          </form>

          <section class="space-y-4 rounded-md border border-line bg-white p-5">
            <h2 class="font-semibold">Draft actions</h2>
            <p v-if="!store.selected" class="text-sm text-muted">Create or select a dashboard to validate, preview, and publish.</p>
            <template v-else>
              <p class="text-sm"><span class="font-medium">{{ store.selected.name }}</span> — v{{ store.selected.draft_version ?? store.selected.current_version }}</p>
              <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" @click="validateDashboard">Validate</button>
                <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" @click="previewDashboard">Preview</button>
                <button type="button" class="rounded-md bg-brand px-3 py-2 text-xs font-semibold text-white" :disabled="store.saving" @click="publishDashboard">Publish</button>
              </div>
              <div v-if="store.validation" class="rounded-md border border-line bg-canvas p-3 text-sm">
                <p class="font-medium">{{ store.validation.valid ? 'Valid layout' : 'Validation issues' }}</p>
                <ul v-if="store.validation.errors?.length" class="mt-2 list-disc pl-5 text-danger-strong">
                  <li v-for="(issue, index) in store.validation.errors" :key="index">{{ issue.message }}</li>
                </ul>
                <ul v-if="store.validation.warnings?.length" class="mt-2 list-disc pl-5 text-muted">
                  <li v-for="(issue, index) in store.validation.warnings" :key="'w-' + index">{{ issue.message }}</li>
                </ul>
              </div>
            </template>
          </section>
        </div>

        <section v-if="store.preview" class="mt-6 rounded-md border border-line bg-white p-5">
          <h2 class="mb-3 font-semibold">Preview</h2>
          <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article v-for="widget in store.preview.widgets" :key="widget.key" class="rounded-md border border-line p-4">
              <div class="mb-2 flex items-center justify-between gap-2">
                <h3 class="font-semibold">{{ widget.title }}</h3>
                <span class="rounded-full bg-canvas px-2 py-0.5 text-xs capitalize">{{ widget.state }}</span>
              </div>
              <p class="mb-2 text-xs text-muted">{{ widget.definition }}</p>
              <p class="text-sm">Freshness: <span class="capitalize">{{ widget.freshness }}</span></p>
              <p v-if="widget.data?.total !== undefined" class="mt-1 text-lg font-semibold">{{ widget.data.total }}</p>
            </article>
          </div>
        </section>

        <section class="mt-6 rounded-md border border-line bg-white p-5">
          <h2 class="mb-3 font-semibold">Dashboards</h2>
          <ul class="space-y-2 text-sm">
            <li v-for="dashboard in store.dashboards" :key="dashboard.id" class="flex items-center justify-between rounded-md border border-line px-3 py-2">
              <button type="button" class="text-left font-medium hover:text-brand" @click="selectDashboard(dashboard)">{{ dashboard.name }}</button>
              <span class="text-xs capitalize text-muted">{{ dashboard.status }}</span>
            </li>
          </ul>
        </section>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useComposableDashboardsStore } from '../stores/composableDashboards'

const drawerOpen = ref(false)
const store = useComposableDashboardsStore()

const defaultWidgets = [
  {
    key: 'members_kpi',
    type: 'kpi',
    metric: 'members',
    title: 'Active members',
    visualization: 'kpi',
    position: 0,
    span: 1,
  },
]

const form = ref({
  name: '',
  branch_id: null,
  role_ids: '',
  widgetsJson: JSON.stringify(defaultWidgets, null, 2),
})

function parseRoleIds(value) {
  return value
    .split(',')
    .map((part) => parseInt(part.trim(), 10))
    .filter((id) => !Number.isNaN(id))
}

function parseWidgets(value) {
  return JSON.parse(value)
}

async function createDashboard() {
  const created = await store.createDashboard({
    name: form.value.name,
    branch_id: form.value.branch_id || null,
    role_ids: parseRoleIds(form.value.role_ids),
    widgets: parseWidgets(form.value.widgetsJson),
  })
  store.selected = created
}

function selectDashboard(dashboard) {
  store.selected = dashboard
  form.value.name = dashboard.name
  form.value.branch_id = dashboard.branch_id
  form.value.role_ids = (dashboard.role_ids ?? []).join(', ')
  form.value.widgetsJson = JSON.stringify(dashboard.widgets ?? defaultWidgets, null, 2)
}

async function validateDashboard() {
  if (!store.selected?.id) return
  await store.validateDashboard(store.selected.id)
}

async function previewDashboard() {
  if (!store.selected?.id) return
  await store.previewDashboard(store.selected.id)
}

async function publishDashboard() {
  if (!store.selected?.id) return
  await store.publishDashboard(store.selected.id)
}

onMounted(async () => {
  await Promise.all([store.loadCatalog(), store.loadDashboards()])
})
</script>
