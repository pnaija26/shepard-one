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
            <p class="truncate text-sm font-semibold text-ink">Operations monitoring</p>
            <p class="truncate text-xs text-muted">Telemetry, threshold alerts, backups, and recovery readiness</p>
          </div>
          <button type="button" class="rounded-md bg-brand px-3 py-2 text-xs font-semibold text-white" :disabled="store.saving" @click="store.collectTelemetry()">
            Collect telemetry
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Platform</p>
          <h1 class="font-serif text-3xl font-bold">Operations health</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>
        <p v-if="store.collectResult" class="mb-4 rounded-md border border-line bg-white px-4 py-3 text-sm">
          Captured {{ store.collectResult.snapshots }} snapshots and raised {{ store.collectResult.alerts }} new alerts.
        </p>

        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <article v-for="component in store.dashboard?.components || []" :key="component.component" class="rounded-md border border-line bg-white p-4">
            <p class="text-xs uppercase tracking-wide text-muted">{{ component.component }}</p>
            <p class="mt-1 text-lg font-semibold capitalize">{{ component.status }}</p>
            <p v-if="component.error_rate != null" class="text-xs text-muted">Error rate: {{ (component.error_rate * 100).toFixed(1) }}%</p>
            <p v-if="component.queue_depth != null" class="text-xs text-muted">Queue depth: {{ component.queue_depth }}</p>
          </article>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Open alerts</h2>
            <ul class="space-y-2 text-sm">
              <li v-for="alert in store.dashboard?.open_alerts || []" :key="alert.id" class="rounded-md border border-line px-3 py-2">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <p class="font-medium">{{ alert.component }} · {{ alert.metric }}</p>
                    <p class="text-xs text-muted">{{ alert.severity }} · {{ alert.status }}</p>
                    <p class="mt-1 text-xs">{{ alert.message }}</p>
                  </div>
                  <div class="flex gap-2 text-xs">
                    <button v-if="alert.status === 'open'" type="button" class="text-brand" @click="store.acknowledgeAlert(alert.id)">Acknowledge</button>
                    <button v-if="alert.status !== 'resolved'" type="button" class="text-brand" @click="store.resolveAlert(alert.id)">Resolve</button>
                  </div>
                </div>
              </li>
            </ul>
          </section>

          <div class="space-y-6">
            <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="recordBackup">
              <h2 class="font-semibold">Record backup run</h2>
              <select v-model="backupForm.run_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option value="incremental">Incremental</option>
                <option value="full">Full</option>
              </select>
              <select v-model="backupForm.status" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
                <option value="stale">Stale</option>
              </select>
              <input v-model="backupForm.failure_reason" placeholder="Failure reason (if failed)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving">Save backup</button>
            </form>

            <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="recordExercise">
              <h2 class="font-semibold">Record recovery exercise</h2>
              <select v-model="exerciseForm.exercise_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option value="restoration">Restoration</option>
                <option value="disaster_recovery">Disaster recovery</option>
              </select>
              <input v-model.number="exerciseForm.measured_rpo_minutes" type="number" min="0" placeholder="Measured RPO (minutes)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model.number="exerciseForm.measured_rto_minutes" type="number" min="0" placeholder="Measured RTO (minutes)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <textarea v-model="exerciseForm.findings" rows="2" placeholder="Findings (comma-separated)" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving">Save exercise</button>
            </form>
          </div>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useOperationsMonitoringStore } from '../stores/operationsMonitoring'

const store = useOperationsMonitoringStore()
const drawerOpen = ref(false)

const backupForm = reactive({
  run_type: 'incremental',
  status: 'completed',
  failure_reason: '',
})

const exerciseForm = reactive({
  exercise_type: 'disaster_recovery',
  measured_rpo_minutes: 45,
  measured_rto_minutes: 180,
  findings: '',
})

async function recordBackup() {
  await store.recordBackup({
    run_type: backupForm.run_type,
    status: backupForm.status,
    failure_reason: backupForm.failure_reason || undefined,
  })
  backupForm.failure_reason = ''
}

async function recordExercise() {
  await store.recordRecoveryExercise({
    exercise_type: exerciseForm.exercise_type,
    measured_rpo_minutes: exerciseForm.measured_rpo_minutes,
    measured_rto_minutes: exerciseForm.measured_rto_minutes,
    verification_evidence: { database_restored: true, api_smoke_tests_passed: true },
    findings: exerciseForm.findings ? exerciseForm.findings.split(',').map((item) => item.trim()).filter(Boolean) : [],
  })
}

onMounted(async () => {
  await Promise.all([
    store.loadCatalog(),
    store.loadDashboard(),
    store.loadBackups(),
    store.loadRecoveryExercises(),
  ])
})
</script>
