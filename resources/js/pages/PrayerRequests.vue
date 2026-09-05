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
            <p class="truncate text-sm font-semibold text-ink">Prayer requests</p>
            <p class="truncate text-xs text-muted">Submit and process with confidentiality</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Care & welfare</p>
          <h1 class="font-serif text-3xl font-bold">Prayer requests</h1>
        </section>

        <p v-if="prayerStore.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ prayerStore.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="submitRequest">
            <h2 class="font-semibold">New prayer request</h2>
            <select v-model="form.category" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="healing">Healing</option>
              <option value="family">Family</option>
              <option value="guidance">Guidance</option>
              <option value="salvation">Salvation</option>
              <option value="thanksgiving">Thanksgiving</option>
              <option value="provision">Provision</option>
              <option value="protection">Protection</option>
              <option value="other">Other</option>
            </select>
            <select v-model="form.priority" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="low">Low</option>
              <option value="normal">Normal</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </select>
            <select v-model="form.confidentiality" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="private">Private</option>
              <option value="prayer_team">Prayer team only</option>
              <option value="pastor_only">Pastor only</option>
              <option value="group">Group</option>
              <option value="public_testimony">Public / Testimony</option>
            </select>
            <input v-if="form.confidentiality === 'group'" v-model="form.church_group_id" type="number" placeholder="Church group ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <textarea v-model="form.request_body" rows="4" required placeholder="Prayer request" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="form.consent_prayer_processing" type="checkbox" />
              Consent to prayer processing
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="form.consent_sharing" type="checkbox" />
              Consent to share with selected audience
            </label>
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="prayerStore.saving">Submit request</button>
          </form>

          <section class="space-y-6">
            <div class="rounded-md border border-line bg-white p-5">
              <h2 class="mb-3 font-semibold">Discoverable queue</h2>
              <ul class="space-y-2 text-sm">
                <li v-for="item in prayerStore.requests" :key="'q-' + item.id" class="rounded-md border border-line p-3">
                  <button type="button" class="w-full text-left" @click="openRequest(item.id)">
                    <p class="font-medium">{{ item.reference }} · {{ item.category_label }}</p>
                    <p class="text-xs text-muted">{{ item.confidentiality_label }} · {{ item.status }}</p>
                  </button>
                </li>
              </ul>
            </div>

            <div class="rounded-md border border-line bg-white p-5">
              <h2 class="mb-3 font-semibold">My requests</h2>
              <ul class="space-y-2 text-sm">
                <li v-for="item in prayerStore.myRequests" :key="item.id" class="rounded-md border border-line p-3">
                  <button type="button" class="w-full text-left" @click="openRequest(item.id)">
                    <p class="font-medium">{{ item.reference }} · {{ item.category_label }}</p>
                    <p class="text-xs text-muted">{{ item.confidentiality_label }} · {{ item.status }}</p>
                  </button>
                </li>
              </ul>
            </div>

            <section v-if="prayerStore.selectedRequest" class="rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="font-semibold">{{ prayerStore.selectedRequest.reference }}</h2>
              <p class="text-xs text-muted">{{ prayerStore.selectedRequest.confidentiality_label }} · {{ prayerStore.selectedRequest.status }}</p>
              <p class="mt-2 whitespace-pre-wrap">{{ prayerStore.selectedRequest.request_body }}</p>
              <p class="mt-2 text-xs text-muted">
                Assigned: {{ prayerStore.selectedRequest.assigned_officer?.name ?? '—' }}
                <span v-if="prayerStore.selectedRequest.acknowledged_at"> · Acknowledged</span>
                <span v-if="prayerStore.selectedRequest.answered_at"> · Answered</span>
                <span v-if="prayerStore.selectedRequest.closed_at"> · Closed</span>
              </p>
              <p v-if="prayerStore.selectedRequest.propagation_pending" class="mt-2 text-xs text-amber-700">Confidentiality change propagating to indexes.</p>

              <div v-if="prayerStore.selectedRequest.can_process" class="mt-4 space-y-2 border-t border-line pt-3">
                <h3 class="font-medium">Process request</h3>
                <input v-model="processForm.assignee_id" type="number" placeholder="Assignee user ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <textarea v-model="processForm.notes" rows="2" placeholder="Permitted notes" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
                <textarea v-model="processForm.restricted_notes" rows="2" placeholder="Restricted notes (processors only)" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
                <div class="flex flex-wrap gap-2">
                  <button type="button" class="rounded-md bg-brand px-3 py-1.5 text-xs font-semibold text-white" :disabled="prayerStore.saving" @click="assignRequest">Assign</button>
                  <button type="button" class="rounded-md border border-line px-3 py-1.5 text-xs" :disabled="prayerStore.saving" @click="acknowledgeRequest">Acknowledge</button>
                  <button type="button" class="rounded-md border border-line px-3 py-1.5 text-xs" :disabled="prayerStore.saving" @click="updateRequest">Update</button>
                  <button type="button" class="rounded-md border border-line px-3 py-1.5 text-xs" :disabled="prayerStore.saving" @click="escalateRequest">Escalate</button>
                  <button type="button" class="rounded-md border border-line px-3 py-1.5 text-xs" :disabled="prayerStore.saving" @click="answerRequest">Answered</button>
                  <button type="button" class="rounded-md border border-line px-3 py-1.5 text-xs" :disabled="prayerStore.saving" @click="closeRequest">Close</button>
                  <button
                    v-if="prayerStore.selectedRequest.confidentiality === 'group'"
                    type="button"
                    class="rounded-md border border-line px-3 py-1.5 text-xs"
                    :disabled="prayerStore.saving"
                    @click="publishRequest"
                  >
                    Publish to group
                  </button>
                </div>
              </div>

              <div v-if="prayerStore.selectedRequest.activities?.length" class="mt-4 space-y-2 border-t border-line pt-3">
                <h3 class="font-medium">Activity</h3>
                <ul class="space-y-1 text-xs text-muted">
                  <li v-for="activity in prayerStore.selectedRequest.activities" :key="activity.id">
                    {{ activity.activity_type }} · {{ activity.status_after }} · {{ activity.notes || '—' }}
                  </li>
                </ul>
              </div>

              <div v-if="prayerStore.selectedRequest.status !== 'withdrawn'" class="mt-4 space-y-2 border-t border-line pt-3">
                <h3 class="font-medium">Narrow confidentiality</h3>
                <select v-model="narrowForm.confidentiality" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                  <option value="private">Private</option>
                  <option value="pastor_only">Pastor only</option>
                  <option value="prayer_team">Prayer team only</option>
                  <option value="group">Group</option>
                </select>
                <button type="button" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="prayerStore.saving" @click="narrowScope">Save stricter scope</button>
                <button type="button" class="ml-2 rounded-md border border-line px-2 py-1 text-xs" :disabled="prayerStore.saving" @click="withdrawRequest">Withdraw sharing</button>
              </div>
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
import { usePrayerRequestsStore } from '../stores/prayerRequests'

const drawerOpen = ref(false)
const prayerStore = usePrayerRequestsStore()

const form = reactive({
  category: 'healing',
  priority: 'normal',
  confidentiality: 'prayer_team',
  church_group_id: '',
  request_body: '',
  consent_prayer_processing: true,
  consent_sharing: true,
})

const narrowForm = reactive({
  confidentiality: 'private',
})

const processForm = reactive({
  assignee_id: '',
  notes: '',
  restricted_notes: '',
})

async function submitRequest() {
  await prayerStore.createRequest({
    category: form.category,
    priority: form.priority,
    confidentiality: form.confidentiality,
    church_group_id: form.confidentiality === 'group' ? Number(form.church_group_id) : undefined,
    request_body: form.request_body,
    consent_prayer_processing: form.consent_prayer_processing,
    consent_sharing: form.consent_sharing,
  }, true)
}

async function openRequest(id) {
  await prayerStore.selectRequest(id)
}

async function narrowScope() {
  if (!prayerStore.selectedRequest) return
  await prayerStore.updateConfidentiality(prayerStore.selectedRequest.id, {
    confidentiality: narrowForm.confidentiality,
    reason: 'Member narrowed confidentiality from UI.',
  })
}

async function withdrawRequest() {
  if (!prayerStore.selectedRequest) return
  await prayerStore.withdraw(prayerStore.selectedRequest.id, {
    reason: 'Member withdrew public/shared prayer request.',
  })
}

async function assignRequest() {
  if (!prayerStore.selectedRequest) return
  await prayerStore.assign(prayerStore.selectedRequest.id, {
    assignee_id: Number(processForm.assignee_id),
    notes: processForm.notes || undefined,
  })
}

async function acknowledgeRequest() {
  if (!prayerStore.selectedRequest) return
  await prayerStore.acknowledge(prayerStore.selectedRequest.id, {
    notes: processForm.notes || undefined,
  })
}

async function updateRequest() {
  if (!prayerStore.selectedRequest) return
  await prayerStore.recordUpdate(prayerStore.selectedRequest.id, {
    notes: processForm.notes || undefined,
    restricted_notes: processForm.restricted_notes || undefined,
    status: 'in_prayer',
  })
}

async function escalateRequest() {
  if (!prayerStore.selectedRequest) return
  await prayerStore.escalate(prayerStore.selectedRequest.id, {
    to_officer_id: Number(processForm.assignee_id),
    notes: processForm.notes || undefined,
  })
}

async function answerRequest() {
  if (!prayerStore.selectedRequest) return
  await prayerStore.markAnswered(prayerStore.selectedRequest.id, {
    notes: processForm.notes || undefined,
    restricted_notes: processForm.restricted_notes || undefined,
  })
}

async function closeRequest() {
  if (!prayerStore.selectedRequest) return
  await prayerStore.close(prayerStore.selectedRequest.id, {
    notes: processForm.notes || undefined,
    restricted_notes: processForm.restricted_notes || undefined,
  })
}

async function publishRequest() {
  if (!prayerStore.selectedRequest) return
  await prayerStore.publishToGroup(prayerStore.selectedRequest.id, {
    notes: processForm.notes || undefined,
  })
}

onMounted(() => {
  prayerStore.fetchMyRequests()
  prayerStore.fetchRequests()
})
</script>
