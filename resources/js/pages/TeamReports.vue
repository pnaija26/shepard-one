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
            <p class="truncate text-sm font-semibold text-ink">Team reports</p>
            <p class="truncate text-xs text-muted">Draft, submit, and review service reports</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Volunteers</p>
          <h1 class="font-serif text-3xl font-bold">Team reports</h1>
        </section>

        <div class="mb-4 flex gap-2">
          <input v-model="teamId" type="number" placeholder="Service team ID" class="rounded-md border border-line px-3 py-2 text-sm" />
          <button type="button" class="rounded-md border border-line px-3 py-2 text-sm" @click="loadTeam">Load reports</button>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
          <section class="space-y-4 rounded-md border border-line bg-white p-5">
            <h2 class="font-semibold">Reports</h2>
            <form class="space-y-2" @submit.prevent="createReport">
              <input v-model="period.start" type="date" required class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="period.end" type="date" required class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="!teamId">Create draft</button>
            </form>
            <ul class="space-y-2 text-sm">
              <li v-for="report in reportsStore.reports" :key="report.id" class="rounded-md border border-line p-3">
                <button type="button" class="w-full text-left" @click="reportsStore.selectReport(report.id)">
                  {{ report.reporting_period_start }} to {{ report.reporting_period_end }} · {{ report.status }} · v{{ report.version }}
                </button>
              </li>
            </ul>
          </section>

          <section class="space-y-4 rounded-md border border-line bg-white p-5 text-sm">
            <h2 class="font-semibold">Report editor</h2>
            <form v-if="reportsStore.selectedReport?.is_editable" class="space-y-2" @submit.prevent="saveDraft">
              <textarea v-model="form.concerns" rows="3" placeholder="Concerns" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
              <input v-model="form.attendance" type="number" placeholder="Attendance" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="form.issues" placeholder="Issues" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <button type="submit" class="rounded-md border border-line px-3 py-2 text-xs">Save draft</button>
            </form>
            <div v-if="reportsStore.selectedReport" class="flex gap-2">
              <button v-if="reportsStore.selectedReport.is_editable" type="button" class="rounded-md bg-brand px-3 py-2 text-xs font-semibold text-white" @click="submitReport">Submit</button>
              <button v-if="reportsStore.selectedReport.status === 'submitted'" type="button" class="rounded-md border border-line px-3 py-2 text-xs" @click="approveReport">Approve</button>
              <button v-if="reportsStore.selectedReport.status === 'submitted'" type="button" class="rounded-md border border-line px-3 py-2 text-xs" @click="returnReport">Return</button>
            </div>
            <div v-if="reportsStore.metrics" class="border-t border-line pt-3">
              <p class="font-medium">Approved metrics</p>
              <p>Approved reports: {{ reportsStore.metrics.approved_reports }}</p>
              <p>Attendance total: {{ reportsStore.metrics.attendance_totals }}</p>
              <p>Pending outside metrics: {{ reportsStore.metrics.pending_in_consolidated_metrics }}</p>
            </div>
          </section>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useTeamReportsStore } from '../stores/teamReports'

const drawerOpen = ref(false)
const reportsStore = useTeamReportsStore()
const teamId = ref('')

const period = reactive({
  start: '2026-08-01',
  end: '2026-08-07',
})

const form = reactive({
  concerns: '',
  attendance: 40,
  issues: '',
})

const loadTeam = async () => {
  if (!teamId.value) return
  await reportsStore.fetchReports(Number(teamId.value))
  await reportsStore.fetchMetrics(Number(teamId.value))
}

const createReport = async () => {
  if (!teamId.value) return
  await reportsStore.createReport(Number(teamId.value), {
    reporting_period_start: period.start,
    reporting_period_end: period.end,
  })
}

const reportPayload = () => ({
  field_values: { attendance: Number(form.attendance), issues: form.issues },
  attachments: [],
  incidents: [],
  concerns: form.concerns,
  results: { attendance_count: Number(form.attendance), services_covered: 1 },
  recommendations: [],
})

const saveDraft = async () => {
  if (!reportsStore.selectedReport?.id) return
  await reportsStore.saveDraft(reportsStore.selectedReport.id, reportPayload())
}

const submitReport = async () => {
  if (!reportsStore.selectedReport?.id) return
  await reportsStore.saveDraft(reportsStore.selectedReport.id, reportPayload())
  await reportsStore.submitReport(reportsStore.selectedReport.id)
}

const approveReport = async () => {
  if (!reportsStore.selectedReport?.id) return
  await reportsStore.reviewReport(reportsStore.selectedReport.id, { decision: 'approved', comments: 'Approved.' })
}

const returnReport = async () => {
  if (!reportsStore.selectedReport?.id) return
  await reportsStore.reviewReport(reportsStore.selectedReport.id, {
    decision: 'returned',
    comments: 'Please add more detail.',
  })
}
</script>
