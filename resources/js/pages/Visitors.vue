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
            <p class="truncate text-sm font-semibold text-ink">Visitors</p>
            <p class="truncate text-xs text-muted">Capture first-time and returning visitors</p>
          </div>
          <button type="button" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" @click="openCapture">
            Capture visitor
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Welcome</p>
          <h1 class="font-serif text-3xl font-bold">Visitor capture</h1>
          <p class="mt-1 text-sm text-muted">Record contact details, decisions, and follow-up consent</p>
        </section>

        <p v-if="visitorsStore.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger">
          {{ visitorsStore.error }}
        </p>

        <p v-if="visitorsStore.duplicateWarning" class="mb-4 rounded-md border border-warning/40 bg-warning/10 px-4 py-3 text-sm">
          Potential duplicate found for
          <span v-for="(match, index) in visitorsStore.duplicateWarning.potential_matches" :key="match.type + '-' + match.id">
            {{ match.full_name }} ({{ match.type }})<span v-if="index < visitorsStore.duplicateWarning.potential_matches.length - 1">, </span>
          </span>.
        </p>

        <div class="mb-4">
          <input v-model="search" type="search" placeholder="Search visitors" class="w-full max-w-md rounded-md border border-line bg-white px-3 py-2 text-sm" @keyup.enter="loadVisitors" />
        </div>

        <div v-if="visitorsStore.loading" class="text-sm text-muted">Loading visitors…</div>

        <div v-else-if="visitorsStore.visitors.length === 0" class="rounded-md border border-line bg-white p-8 text-center text-sm text-muted">
          No visitors captured yet.
        </div>

        <div v-else class="overflow-hidden rounded-md border border-line bg-white">
          <table class="min-w-full text-left text-sm">
            <thead class="border-b border-line bg-canvas/60 text-xs uppercase tracking-wide text-muted">
              <tr>
                <th class="px-4 py-3">Visitor</th>
                <th class="px-4 py-3">Branch</th>
                <th class="px-4 py-3">Visits</th>
                <th class="px-4 py-3"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="visitor in visitorsStore.visitors" :key="visitor.id" class="border-b border-line last:border-b-0">
                <td class="px-4 py-3">
                  <p class="font-medium">{{ visitor.full_name }}</p>
                  <p class="text-xs text-muted">{{ visitor.email || visitor.phone || 'No contact' }}</p>
                </td>
                <td class="px-4 py-3">{{ visitor.branch?.name || '—' }}</td>
                <td class="px-4 py-3">{{ visitor.visit_count || 0 }}</td>
                <td class="px-4 py-3 text-right">
                  <button type="button" class="text-xs font-semibold text-brand hover:underline" @click="openVisitor(visitor.id)">Open</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </main>
    </div>

    <div v-if="showCapture" class="fixed inset-0 z-50 flex items-center justify-center bg-ink/45 p-4" @click.self="showCapture = false">
      <form class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-md border border-line bg-white p-6 shadow-lg" @submit.prevent="submitCapture">
        <h2 class="font-serif text-xl font-bold">{{ selectedId ? 'Record returning visit' : 'Capture visitor' }}</h2>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
          <template v-if="!selectedId">
            <input v-model="form.first_name" required placeholder="First name" class="rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.last_name" required placeholder="Last name" class="rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.email" type="email" placeholder="Email" class="rounded-md border border-line px-3 py-2 text-sm sm:col-span-2" />
            <input v-model="form.phone" placeholder="Phone" class="rounded-md border border-line px-3 py-2 text-sm sm:col-span-2" />
            <input v-model="form.inviter_name" placeholder="Inviter name" class="rounded-md border border-line px-3 py-2 text-sm sm:col-span-2" />
          </template>
          <input v-model="form.branch_id" required type="number" placeholder="Branch ID" class="rounded-md border border-line px-3 py-2 text-sm" />
          <input v-model="form.visit_date" required type="date" class="rounded-md border border-line px-3 py-2 text-sm" />
          <input v-model="form.service_or_event" placeholder="Service or event" class="rounded-md border border-line px-3 py-2 text-sm sm:col-span-2" />
          <select v-model="form.source" class="rounded-md border border-line px-3 py-2 text-sm">
            <option value="service">Service</option>
            <option value="event">Event</option>
            <option value="outreach">Outreach</option>
            <option value="referral">Referral</option>
          </select>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="form.membership_interest" type="checkbox" />
            Membership interest
          </label>
          <textarea v-model="form.prayer_needs" placeholder="Prayer needs (restricted)" rows="2" class="rounded-md border border-line px-3 py-2 text-sm sm:col-span-2"></textarea>
          <textarea v-model="form.salvation_response" placeholder="Salvation response (restricted)" rows="2" class="rounded-md border border-line px-3 py-2 text-sm sm:col-span-2"></textarea>
          <label class="flex items-center gap-2 text-sm sm:col-span-2">
            <input v-model="form.consent_data_processing" type="checkbox" required />
            Data processing consent
          </label>
          <label class="flex items-center gap-2 text-sm sm:col-span-2">
            <input v-model="form.consent_follow_up" type="checkbox" />
            Consent to follow-up
          </label>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" class="rounded-md border border-line px-4 py-2 text-sm" @click="closeCapture">Cancel</button>
          <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="visitorsStore.saving">
            {{ visitorsStore.saving ? 'Saving…' : 'Save' }}
          </button>
        </div>
      </form>
    </div>

    <div v-if="selectedId && visitorsStore.selectedVisitor" class="fixed inset-0 z-50 flex items-center justify-center bg-ink/45 p-4" @click.self="selectedId = null">
      <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-md border border-line bg-white p-6 shadow-lg">
        <div class="mb-4 flex items-start justify-between gap-4">
          <div>
            <h2 class="font-serif text-xl font-bold">{{ visitorsStore.selectedVisitor.full_name }}</h2>
            <p class="text-sm text-muted">Source: {{ visitorsStore.selectedVisitor.original_source }}</p>
          </div>
          <button type="button" class="text-sm text-muted" @click="selectedId = null">Close</button>
        </div>
        <button type="button" class="mb-4 rounded-md border border-line px-3 py-1.5 text-xs font-semibold" @click="openReturningVisit">Record visit</button>
        <ul class="space-y-3 text-sm">
          <li v-for="visit in visitorsStore.selectedVisitor.visits" :key="visit.id" class="rounded-md border border-line p-3">
            <p class="font-medium">{{ visit.visit_date }} · {{ visit.service_or_event || visit.source }}</p>
            <p v-if="visit.decisions?.length" class="text-xs text-muted">Decisions: {{ visit.decisions.join(', ') }}</p>
            <p v-if="visit.prayer_needs" class="mt-1 text-xs">Prayer: {{ visit.prayer_needs }}</p>
            <p v-else-if="visit.has_restricted_content" class="mt-1 text-xs text-muted">Restricted content on file</p>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useVisitorsStore } from '../stores/visitors'

const visitorsStore = useVisitorsStore()
const drawerOpen = ref(false)
const showCapture = ref(false)
const selectedId = ref(null)
const search = ref('')

const form = reactive({
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  inviter_name: '',
  branch_id: '',
  visit_date: new Date().toISOString().slice(0, 10),
  service_or_event: '',
  source: 'service',
  prayer_needs: '',
  salvation_response: '',
  membership_interest: false,
  consent_data_processing: true,
  consent_follow_up: true,
  decisions: ['salvation'],
})

const loadVisitors = async () => {
  await visitorsStore.fetchVisitors(search.value)
}

const openCapture = () => {
  selectedId.value = null
  showCapture.value = true
}

const openReturningVisit = () => {
  showCapture.value = true
}

const closeCapture = () => {
  showCapture.value = false
}

const openVisitor = async (id) => {
  selectedId.value = id
  await visitorsStore.fetchVisitor(id)
}

const submitCapture = async () => {
  const payload = { ...form, membership_interest: !!form.membership_interest }
  if (selectedId.value) {
    await visitorsStore.recordVisit(selectedId.value, payload)
  } else {
    const result = await visitorsStore.captureVisitor(payload)
    if (result?.duplicate) return
  }
  showCapture.value = false
  if (selectedId.value) await openVisitor(selectedId.value)
}

onMounted(loadVisitors)
</script>
