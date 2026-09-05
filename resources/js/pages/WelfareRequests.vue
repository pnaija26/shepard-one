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
            <p class="truncate text-sm font-semibold text-ink">Welfare requests</p>
            <p class="truncate text-xs text-muted">Confidential assistance requests and drafts</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Care & welfare</p>
          <h1 class="font-serif text-3xl font-bold">Welfare requests</h1>
        </section>

        <p v-if="welfareStore.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ welfareStore.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="saveDraft">
            <h2 class="font-semibold">New request draft</h2>
            <input v-model="form.beneficiary_member_id" type="number" required placeholder="Beneficiary member ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.request_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="financial">Financial</option>
              <option value="food">Food</option>
              <option value="housing">Housing</option>
              <option value="medical">Medical</option>
              <option value="pastoral">Pastoral</option>
              <option value="other">Other</option>
            </select>
            <select v-model="form.priority" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="low">Low</option>
              <option value="normal">Normal</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </select>
            <input v-model="form.requested_value" type="number" min="0" placeholder="Requested value" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <textarea v-model="form.description" rows="4" required placeholder="Describe the need" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="form.consent_data_processing" type="checkbox" />
              Consent to data processing
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="form.consent_welfare_review" type="checkbox" />
              Consent to welfare review
            </label>
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="welfareStore.saving">Save draft</button>
          </form>

          <section class="space-y-6">
            <div class="rounded-md border border-line bg-white p-5">
              <h2 class="mb-3 font-semibold">Requests in scope</h2>
              <ul class="space-y-2 text-sm">
                <li v-for="request in welfareStore.requests" :key="request.id" class="rounded-md border border-line p-3">
                  <button type="button" class="w-full text-left" @click="selectRequest(request.id)">
                    <p class="font-medium">{{ request.case_number }} · {{ request.request_type }}</p>
                    <p class="text-xs text-muted">{{ request.status }} · {{ request.priority }}</p>
                  </button>
                </li>
              </ul>
            </div>

            <section v-if="welfareStore.selectedRequest" class="rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="font-semibold">{{ welfareStore.selectedRequest.case_number }}</h2>
              <p>{{ welfareStore.selectedRequest.description }}</p>
              <p class="mt-2">Status: {{ welfareStore.selectedRequest.status }} · Value: {{ welfareStore.selectedRequest.requested_value ?? '—' }}</p>
              <p class="mt-1 text-xs text-muted">{{ welfareStore.selectedRequest.beneficiary_status_message }}</p>
              <ul v-if="welfareStore.selectedRequest.validation_errors?.length" class="mt-2 text-xs text-red-700">
                <li v-for="(error, index) in welfareStore.selectedRequest.validation_errors" :key="index">{{ error.message }}</li>
              </ul>
              <button v-if="welfareStore.selectedRequest.status === 'draft'" type="button" class="mt-3 rounded-md border border-line px-2 py-1 text-xs" @click="submitSelected">Submit request</button>

              <div v-if="['submitted', 'under_assessment', 'returned_for_info', 'escalated'].includes(welfareStore.selectedRequest.status)" class="mt-4 space-y-2 border-t border-line pt-3">
                <h3 class="font-medium">Record assessment</h3>
                <textarea v-model="assessForm.assessment_notes" rows="3" placeholder="Assessment notes" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
                <select v-model="assessForm.recommendation" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                  <option value="approve">Approve</option>
                  <option value="partial_approve">Partial approve</option>
                  <option value="decline">Decline</option>
                  <option value="defer">Defer</option>
                </select>
                <input v-model="assessForm.proposed_value" type="number" min="0" placeholder="Proposed value" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <button type="button" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="welfareStore.saving" @click="completeAssessment">Complete recommendation</button>
                <button type="button" class="ml-2 rounded-md border border-line px-2 py-1 text-xs" @click="returnForInfo">Return for information</button>
              </div>

              <ul v-if="welfareStore.selectedRequest.assessments?.length" class="mt-3 space-y-1 text-xs text-muted">
                <li v-for="assessment in welfareStore.selectedRequest.assessments" :key="assessment.id">
                  v{{ assessment.version }} · {{ assessment.recommendation }} · {{ assessment.complete ? 'complete' : 'draft' }}
                </li>
              </ul>

              <div v-if="welfareStore.selectedRequest.approvals?.steps?.length" class="mt-4 space-y-2 border-t border-line pt-3">
                <h3 class="font-medium">Approval routing</h3>
                <p class="text-xs text-muted">Policy v{{ welfareStore.selectedRequest.approvals.approval_config_version?.version }}</p>
                <ul class="space-y-1 text-xs">
                  <li v-for="step in welfareStore.selectedRequest.approvals.steps" :key="step.id">
                    {{ step.sequence }}. {{ step.level }} · {{ step.status }}{{ step.is_current ? ' (current)' : '' }}
                  </li>
                </ul>
                <button
                  v-if="welfareStore.selectedRequest.status === 'pending_approval'"
                  type="button"
                  class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white"
                  :disabled="welfareStore.saving"
                  @click="approveCurrent"
                >
                  Approve current step
                </button>
              </div>

              <div v-if="['approved', 'disbursed'].includes(welfareStore.selectedRequest.status)" class="mt-4 space-y-2 border-t border-line pt-3">
                <h3 class="font-medium">Assistance delivery</h3>
                <input v-model="deliveryForm.amount" type="number" min="0" placeholder="Amount" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <input v-model="deliveryForm.reference" required placeholder="Payment reference" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <button type="button" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="welfareStore.saving" @click="recordDelivery">Record delivery</button>
              </div>

              <ul v-if="welfareStore.selectedRequest.deliveries?.length" class="mt-3 space-y-2 text-xs">
                <li v-for="delivery in welfareStore.selectedRequest.deliveries" :key="delivery.id" class="rounded-md border border-line p-2">
                  {{ delivery.method }} · {{ delivery.amount ?? '•••' }} · {{ delivery.confirmation?.status }}
                  <button
                    v-if="delivery.confirmation?.status === 'pending'"
                    type="button"
                    class="ml-2 rounded-md border border-line px-2 py-1"
                    @click="confirmDelivery(delivery.id)"
                  >
                    Confirm
                  </button>
                </li>
              </ul>

              <div v-if="['follow_up', 'disbursed', 'approved'].includes(welfareStore.selectedRequest.status)" class="mt-4 space-y-2 border-t border-line pt-3">
                <h3 class="font-medium">Follow-up</h3>
                <p v-if="welfareStore.selectedRequest.follow_up_due_on" class="text-xs text-muted">Due {{ welfareStore.selectedRequest.follow_up_due_on }}</p>
                <select v-model="followUpForm.outcome" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                  <option value="contacted">Contacted</option>
                  <option value="assisted_further">Assisted further</option>
                  <option value="no_response">No response</option>
                  <option value="resolved">Resolved</option>
                  <option value="unresolved">Unresolved</option>
                  <option value="referred">Referred</option>
                </select>
                <select v-model="followUpForm.further_action" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                  <option value="continue">Continue</option>
                  <option value="reschedule">Reschedule</option>
                  <option value="escalate">Escalate</option>
                </select>
                <input v-model="followUpForm.follow_up_due_on" type="date" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <textarea v-model="followUpForm.notes" rows="2" placeholder="Follow-up notes" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
                <button type="button" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="welfareStore.saving" @click="recordFollowUp">Record follow-up</button>
                <button type="button" class="ml-2 rounded-md border border-line px-2 py-1 text-xs" :disabled="welfareStore.saving" @click="closeCase">Close case</button>
              </div>

              <ul v-if="welfareStore.selectedRequest.follow_ups?.length" class="mt-3 space-y-1 text-xs text-muted">
                <li v-for="entry in welfareStore.selectedRequest.follow_ups" :key="entry.id">
                  {{ entry.entry_type }} · {{ entry.outcome }} · {{ entry.further_action }}
                </li>
              </ul>
            </section>

            <section class="rounded-md border border-line bg-white p-5 text-sm">
              <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="font-semibold">Welfare report</h2>
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="loadReport">Refresh</button>
              </div>
              <div v-if="welfareStore.report" class="space-y-2 text-xs">
                <p>Cases: {{ welfareStore.report.summary.case_count }} · Beneficiaries: {{ welfareStore.report.summary.beneficiary_count }}</p>
                <p>Expenditure: {{ welfareStore.report.summary.expenditure_total ?? '•••' }} · Outstanding: {{ welfareStore.report.summary.outstanding_count }} · Overdue: {{ welfareStore.report.summary.overdue_follow_ups }}</p>
                <p class="text-muted">Identity minimized unless authorized</p>
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
import { useWelfareRequestsStore } from '../stores/welfareRequests'

const drawerOpen = ref(false)
const welfareStore = useWelfareRequestsStore()

const form = reactive({
  beneficiary_member_id: '',
  request_type: 'financial',
  priority: 'normal',
  requested_value: '',
  description: '',
  consent_data_processing: true,
  consent_welfare_review: true,
})

const assessForm = reactive({
  assessment_notes: '',
  recommendation: 'partial_approve',
  proposed_value: '',
})

const deliveryForm = reactive({
  amount: '',
  reference: '',
})

const followUpForm = reactive({
  outcome: 'contacted',
  further_action: 'continue',
  follow_up_due_on: '',
  notes: '',
})

async function saveDraft() {
  await welfareStore.createRequest({
    branch_id: 1,
    beneficiary_member_id: Number(form.beneficiary_member_id),
    request_type: form.request_type,
    priority: form.priority,
    requested_value: form.requested_value ? Number(form.requested_value) : null,
    description: form.description,
    consent_data_processing: form.consent_data_processing,
    consent_welfare_review: form.consent_welfare_review,
    documents: [{
      filename: 'supporting.pdf',
      mime_type: 'application/pdf',
      size_bytes: 1024,
      content_hash: `ui-${Date.now()}`,
    }],
  })
}

async function selectRequest(id) {
  await welfareStore.selectRequest(id)
}

async function submitSelected() {
  if (!welfareStore.selectedRequest) return
  await welfareStore.submitRequest(welfareStore.selectedRequest.id)
}

async function completeAssessment() {
  if (!welfareStore.selectedRequest) return
  await welfareStore.assessRequest(welfareStore.selectedRequest.id, {
    assessment_notes: assessForm.assessment_notes || 'Assessment completed from UI.',
    verified_documents: ['supporting.pdf'],
    priority: welfareStore.selectedRequest.priority || 'normal',
    recommendation: assessForm.recommendation,
    proposed_assistance_type: 'cash',
    proposed_value: Number(assessForm.proposed_value || 0),
    follow_up_needs: 'Follow up after disbursement.',
    complete: true,
  })
}

async function returnForInfo() {
  if (!welfareStore.selectedRequest) return
  await welfareStore.recordCondition(welfareStore.selectedRequest.id, {
    condition_type: 'missing_evidence',
    notes: 'Additional supporting documents required.',
  })
}

async function approveCurrent() {
  if (!welfareStore.selectedRequest) return
  await welfareStore.decide(welfareStore.selectedRequest.id, {
    decision: 'approved',
    reason: 'Approved from welfare UI.',
  })
}

async function recordDelivery() {
  if (!welfareStore.selectedRequest) return
  await welfareStore.recordDelivery(welfareStore.selectedRequest.id, {
    delivery_type: 'disbursement',
    method: 'bank_transfer',
    amount: Number(deliveryForm.amount),
    delivered_on: new Date().toISOString().slice(0, 10),
    reference: deliveryForm.reference || `UI-${Date.now()}`,
  })
}

async function confirmDelivery(deliveryId) {
  await welfareStore.confirmDelivery(deliveryId, { status: 'confirmed' })
}

async function recordFollowUp() {
  if (!welfareStore.selectedRequest) return
  await welfareStore.recordFollowUp(welfareStore.selectedRequest.id, {
    outcome: followUpForm.outcome,
    further_action: followUpForm.further_action,
    follow_up_due_on: followUpForm.follow_up_due_on || undefined,
    notes: followUpForm.notes || 'Follow-up recorded from UI.',
  })
}

async function closeCase() {
  if (!welfareStore.selectedRequest) return
  await welfareStore.closeRequest(welfareStore.selectedRequest.id, {
    closure_reason: 'resolved',
    notes: 'Closed from welfare UI.',
    evidence: [{
      filename: 'closure.pdf',
      mime_type: 'application/pdf',
      size_bytes: 1024,
      content_hash: `close-${Date.now()}`,
    }],
  })
}

async function loadReport() {
  await welfareStore.fetchReport()
}

onMounted(() => {
  welfareStore.fetchRequests()
  welfareStore.fetchMyRequests()
  welfareStore.fetchReport()
})
</script>
