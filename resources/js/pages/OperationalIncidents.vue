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
            <p class="truncate text-sm font-semibold text-ink">Operational incidents</p>
            <p class="truncate text-xs text-muted">Report, investigate, and close incidents</p>
          </div>
          <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="incidentsStore.saving" @click="incidentsStore.processEscalations()">
            Process escalations
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Operations</p>
          <h1 class="font-serif text-3xl font-bold">Incidents</h1>
        </section>

        <p v-if="incidentsStore.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ incidentsStore.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-4 rounded-md border border-line bg-white p-5" @submit.prevent="submit">
            <h2 class="font-semibold">Report incident</h2>
            <input v-model="form.branch_id" type="number" required placeholder="Branch ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.classification" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="medical">Medical</option>
              <option value="child_safety">Child safety</option>
              <option value="security">Security</option>
              <option value="equipment">Equipment</option>
              <option value="complaint">Complaint</option>
              <option value="technical">Technical</option>
            </select>
            <select v-model="form.priority" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="low">Low</option>
              <option value="normal">Normal</option>
              <option value="high">High</option>
              <option value="critical">Critical</option>
            </select>
            <input v-model="form.occurred_at" type="datetime-local" required class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.location" required placeholder="Location" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <textarea v-model="form.description" required rows="4" placeholder="Description" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="incidentsStore.saving">
              Report incident
            </button>
          </form>

          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Open incidents</h2>
            <ul class="space-y-3 text-sm">
              <li v-for="item in incidentsStore.items" :key="item.id" class="rounded-md border border-line p-3">
                <p class="font-medium">{{ item.reference }} · {{ item.classification_label }}</p>
                <p class="text-xs text-muted">{{ item.priority }} · {{ item.status }} · {{ item.assigned_team_label }}</p>
                <p class="mt-1">{{ item.description }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                  <button v-if="item.status !== 'pending_review' && item.status !== 'closed'" type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="investigate(item.id)">Investigate</button>
                  <button v-if="item.status !== 'pending_review' && item.status !== 'closed'" type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="resolve(item.id)">Resolve</button>
                  <button v-if="item.status === 'pending_review'" type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="approve(item.id)">Approve closure</button>
                </div>
              </li>
            </ul>
          </section>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useIncidentsStore } from '../stores/incidents'

const drawerOpen = ref(false)
const incidentsStore = useIncidentsStore()

const form = reactive({
  branch_id: '',
  classification: 'equipment',
  priority: 'normal',
  occurred_at: '',
  location: '',
  description: '',
})

const submit = async () => {
  await incidentsStore.reportIncident({
    ...form,
    branch_id: Number(form.branch_id),
    occurred_at: new Date(form.occurred_at).toISOString(),
  })
}

const investigate = async (id) => {
  await incidentsStore.recordActivity(id, {
    activity_type: 'investigation',
    notes: 'Investigation started from incident queue.',
  })
}

const resolve = async (id) => {
  await incidentsStore.recordActivity(id, {
    activity_type: 'resolution',
    closure_outcome: 'Issue addressed by response team.',
  })
}

const approve = async (id) => {
  await incidentsStore.reviewIncident(id, {
    decision: 'approve',
    notes: 'Closure approved.',
  })
}

onMounted(() => {
  incidentsStore.fetchIncidents()
})
</script>
