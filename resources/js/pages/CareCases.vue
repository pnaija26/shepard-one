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
            <p class="truncate text-sm font-semibold text-ink">Pastoral care</p>
            <p class="truncate text-xs text-muted">Restricted member care cases</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Care & welfare</p>
          <h1 class="font-serif text-3xl font-bold">Care cases</h1>
        </section>

        <p v-if="careStore.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ careStore.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createCase">
            <h2 class="font-semibold">New restricted care case</h2>
            <input v-model="form.beneficiary_member_id" type="number" required placeholder="Beneficiary member ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.category" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="hospital_visit">Hospital visit</option>
              <option value="bereavement">Bereavement</option>
              <option value="counselling">Counselling</option>
              <option value="marriage_family">Marriage or family</option>
              <option value="new_baby">New baby</option>
              <option value="emergency">Emergency</option>
              <option value="pastoral_visit">Pastoral visit</option>
              <option value="follow_up">Follow-up need</option>
            </select>
            <select v-model="form.priority" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="low">Low</option>
              <option value="normal">Normal</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </select>
            <select v-model="form.consent_basis" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="member_request">Member requested care</option>
              <option value="family_request">Family requested care</option>
              <option value="pastoral_observation">Pastoral observation</option>
              <option value="emergency">Emergency intervention</option>
              <option value="referral">Internal referral</option>
            </select>
            <select v-model="form.confidentiality" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="care_team">Care team only</option>
              <option value="pastor_only">Pastor only</option>
              <option value="assigned_only">Assigned caregiver only</option>
              <option value="safeguarding">Safeguarding clearance</option>
            </select>
            <textarea v-model="form.description" rows="4" required placeholder="Care need description" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
            <textarea v-model="form.sensitive_notes" rows="2" placeholder="Sensitive notes (encrypted)" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="careStore.saving">Create case</button>
          </form>

          <section class="space-y-6">
            <div class="rounded-md border border-line bg-white p-5">
              <h2 class="mb-3 font-semibold">Cases in scope</h2>
              <ul class="space-y-2 text-sm">
                <li v-for="item in careStore.cases" :key="item.id" class="rounded-md border border-line p-3">
                  <button type="button" class="w-full text-left" @click="openCase(item.id)">
                    <p class="font-medium">{{ item.case_number }} · {{ item.category_label }}</p>
                    <p class="text-xs text-muted">{{ item.priority }} · {{ item.status }} · {{ item.confidentiality_label }}</p>
                  </button>
                </li>
              </ul>
            </div>

            <section v-if="careStore.selectedCase" class="rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="font-semibold">{{ careStore.selectedCase.case_number }}</h2>
              <p class="text-xs text-muted">{{ careStore.selectedCase.data_classification }} · {{ careStore.selectedCase.confidentiality_label }}</p>
              <p class="mt-2">Status: {{ careStore.selectedCase.status }} · Assigned: {{ careStore.selectedCase.assigned_officer?.name ?? '—' }}</p>
              <p v-if="careStore.selectedCase.next_follow_up_on" class="text-xs text-muted">Next follow-up: {{ careStore.selectedCase.next_follow_up_on }}</p>
              <p v-if="careStore.selectedCase.restricted_details_omitted" class="mt-2 text-xs text-amber-700">Restricted details omitted — sensitivity clearance required.</p>
              <template v-else>
                <p class="mt-2">Beneficiary: {{ careStore.selectedCase.beneficiary?.name ?? '—' }}</p>
                <p class="mt-2 whitespace-pre-wrap">{{ careStore.selectedCase.description }}</p>
                <p v-if="careStore.selectedCase.sensitive_notes" class="mt-2 text-xs text-muted">Notes: {{ careStore.selectedCase.sensitive_notes }}</p>
              </template>

              <div v-if="careStore.selectedCase.status !== 'closed'" class="mt-4 space-y-2 border-t border-line pt-3">
                <h3 class="font-medium">Record care activity</h3>
                <select v-model="activityForm.activity_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                  <option value="contact">Contact</option>
                  <option value="visit">Visit</option>
                  <option value="care_action">Care action</option>
                  <option value="outcome">Outcome</option>
                  <option value="note">Note</option>
                  <option value="follow_up">Follow-up</option>
                </select>
                <select v-model="activityForm.outcome" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                  <option value="reached">Reached</option>
                  <option value="partial_progress">Partial progress</option>
                  <option value="resolved">Resolved</option>
                  <option value="unresolved">Unresolved</option>
                  <option value="rescheduled">Rescheduled</option>
                </select>
                <input v-model="activityForm.next_follow_up_on" type="date" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <textarea v-model="activityForm.notes" rows="2" placeholder="Activity notes" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
                <textarea v-model="activityForm.restricted_note" rows="2" placeholder="Restricted note (encrypted)" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
                <button type="button" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="careStore.saving" @click="recordActivity">Add entry</button>
                <button type="button" class="ml-2 rounded-md border border-line px-2 py-1 text-xs" :disabled="careStore.saving" @click="escalateCase">Escalate</button>
                <button type="button" class="ml-2 rounded-md border border-line px-2 py-1 text-xs" :disabled="careStore.saving" @click="closeCase">Close case</button>
              </div>

              <div v-else class="mt-4 border-t border-line pt-3">
                <p class="text-xs text-muted">Closed · {{ careStore.selectedCase.closure_reason }}</p>
                <button type="button" class="mt-2 rounded-md border border-line px-2 py-1 text-xs" :disabled="careStore.saving" @click="reopenCase">Reopen case</button>
              </div>

              <ul v-if="careStore.selectedCase.activities?.length" class="mt-3 space-y-1 text-xs text-muted">
                <li v-for="entry in careStore.selectedCase.activities" :key="entry.id">
                  {{ entry.activity_type }} · {{ entry.outcome || '—' }} · {{ entry.next_follow_up_on || 'no due date' }}
                </li>
              </ul>
            </section>
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
import { useCareCasesStore } from '../stores/careCases'

const drawerOpen = ref(false)
const careStore = useCareCasesStore()

const form = reactive({
  beneficiary_member_id: '',
  category: 'pastoral_visit',
  priority: 'normal',
  consent_basis: 'member_request',
  confidentiality: 'care_team',
  description: '',
  sensitive_notes: '',
})

const activityForm = reactive({
  activity_type: 'visit',
  outcome: 'reached',
  next_follow_up_on: '',
  notes: '',
  restricted_note: '',
})

async function createCase() {
  await careStore.createCase({
    branch_id: 1,
    beneficiary_member_id: Number(form.beneficiary_member_id),
    category: form.category,
    priority: form.priority,
    consent_basis: form.consent_basis,
    confidentiality: form.confidentiality,
    description: form.description,
    sensitive_notes: form.sensitive_notes || null,
    evidence: [{
      filename: 'care-note.pdf',
      mime_type: 'application/pdf',
      size_bytes: 1024,
      content_hash: `care-${Date.now()}`,
    }],
  })
}

async function openCase(id) {
  await careStore.selectCase(id)
}

async function recordActivity() {
  if (!careStore.selectedCase) return
  await careStore.recordActivity(careStore.selectedCase.id, {
    activity_type: activityForm.activity_type,
    outcome: activityForm.outcome,
    next_follow_up_on: activityForm.next_follow_up_on || undefined,
    notes: activityForm.notes || 'Care activity recorded from UI.',
    restricted_note: activityForm.restricted_note || null,
  })
}

async function escalateCase() {
  if (!careStore.selectedCase) return
  await careStore.escalate(careStore.selectedCase.id, {
    trigger_type: 'unresolved_need',
    notes: 'Escalated from care UI.',
  })
}

async function closeCase() {
  if (!careStore.selectedCase) return
  await careStore.closeCase(careStore.selectedCase.id, {
    closure_reason: 'resolved',
    closure_outcome: 'Care need met and member supported.',
    future_care_plan: 'Check in again at the next pastoral visit.',
  })
}

async function reopenCase() {
  if (!careStore.selectedCase) return
  await careStore.reopenCase(careStore.selectedCase.id, {
    reason: 'Additional pastoral support requested after closure.',
  })
}

onMounted(() => {
  careStore.fetchCases()
})
</script>
