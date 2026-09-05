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
            <p class="truncate text-sm font-semibold text-ink">Members</p>
            <p class="truncate text-xs text-muted">Register and maintain member profiles</p>
          </div>
          <button type="button" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-hover" @click="showRegister = true">
            Register member
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Membership</p>
          <h1 class="font-serif text-3xl font-bold">Member profiles</h1>
          <p class="mt-1 text-sm text-muted">One reliable church-wide record for each person</p>
        </section>

        <p v-if="membersStore.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger" role="alert">
          {{ membersStore.error }}
        </p>

        <p v-if="duplicateWarning" class="mb-4 rounded-md border border-warning/40 bg-warning/10 px-4 py-3 text-sm text-ink" role="alert">
          Potential duplicate detected for
          <span v-for="(match, index) in duplicateWarning.potential_matches" :key="match.id">
            {{ match.full_name }} ({{ match.membership_id }})<span v-if="index < duplicateWarning.potential_matches.length - 1">, </span>
          </span>.
          Review before creating another record.
        </p>

        <div class="mb-4">
          <input
            v-model="membersStore.search"
            type="search"
            placeholder="Search by name, email, or membership ID"
            class="w-full max-w-md rounded-md border border-line bg-white px-3 py-2 text-sm"
          />
        </div>

        <div v-if="membersStore.loading" class="text-sm text-muted">Loading members…</div>

        <div v-else-if="membersStore.filteredMembers.length === 0" class="rounded-md border border-line bg-white p-8 text-center text-sm text-muted">
          No members found in your scope yet.
        </div>

        <div v-else class="overflow-hidden rounded-md border border-line bg-white">
          <table class="min-w-full text-left text-sm">
            <thead class="border-b border-line bg-canvas/60 text-xs uppercase tracking-wide text-muted">
              <tr>
                <th class="px-4 py-3 font-semibold">Member</th>
                <th class="px-4 py-3 font-semibold">Membership ID</th>
                <th class="px-4 py-3 font-semibold">Branch</th>
                <th class="px-4 py-3 font-semibold">Status</th>
                <th class="px-4 py-3 font-semibold"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="member in membersStore.filteredMembers" :key="member.id" class="border-b border-line last:border-b-0">
                <td class="px-4 py-3">
                  <p class="font-medium">{{ member.full_name }}</p>
                  <p class="text-xs text-muted">{{ member.email || member.phone || 'No contact' }}</p>
                </td>
                <td class="px-4 py-3 font-mono text-xs">{{ member.membership_id }}</td>
                <td class="px-4 py-3">{{ member.branch?.name || '—' }}</td>
                <td class="px-4 py-3 capitalize">{{ member.membership_status }}</td>
                <td class="px-4 py-3 text-right">
                  <button type="button" class="text-xs font-semibold text-brand hover:underline" @click="openMember(member.id)">Open</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <section v-if="duplicateFlags.length" class="mt-8">
          <h2 class="mb-3 font-semibold">Duplicate review queue</h2>
          <div class="space-y-3">
            <div v-for="flag in duplicateFlags" :key="flag.id" class="flex flex-col gap-3 rounded-md border border-line bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p class="font-medium">
                  {{ flag.member_a?.full_name }} ↔ {{ flag.member_b?.full_name }}
                </p>
                <p class="text-xs text-muted">
                  {{ flag.confidence }} confidence · {{ flag.match_reason }} · {{ flag.source }}
                </p>
              </div>
              <div class="flex gap-2">
                <button type="button" class="rounded-md border border-line px-3 py-1.5 text-xs font-semibold" @click="openDuplicateReview(flag.id)">Review</button>
              </div>
            </div>
          </div>
        </section>

        <section v-if="pendingReviews.length" class="mt-8">
          <h2 class="mb-3 font-semibold">Pending profile changes</h2>
          <div class="space-y-3">
            <div v-for="request in pendingReviews" :key="request.id" class="flex flex-col gap-3 rounded-md border border-line bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p class="font-medium">{{ request.member?.full_name }} — {{ request.field_name }}</p>
                <p class="text-xs text-muted">Proposed: {{ displayValue(request.proposed_value) }}</p>
              </div>
              <div class="flex gap-2">
                <button type="button" class="rounded-md border border-line px-3 py-1.5 text-xs font-semibold" @click="rejectReview(request.id)">Reject</button>
                <button type="button" class="rounded-md bg-brand px-3 py-1.5 text-xs font-semibold text-white" @click="approveReview(request.id)">Approve</button>
              </div>
            </div>
          </div>
        </section>
      </main>
    </div>

    <div v-if="showRegister" class="fixed inset-0 z-50 flex items-center justify-center bg-ink/45 p-4" @click.self="showRegister = false">
      <form class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-md border border-line bg-white p-6 shadow-lg" @submit.prevent="submitRegistration">
        <h2 class="font-serif text-xl font-bold">Register member</h2>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
          <input v-model="form.first_name" required placeholder="First name" class="rounded-md border border-line px-3 py-2 text-sm" />
          <input v-model="form.last_name" required placeholder="Last name" class="rounded-md border border-line px-3 py-2 text-sm" />
          <input v-model="form.email" type="email" placeholder="Email" class="rounded-md border border-line px-3 py-2 text-sm sm:col-span-2" />
          <input v-model="form.phone" placeholder="Phone" class="rounded-md border border-line px-3 py-2 text-sm sm:col-span-2" />
          <input v-model="form.branch_id" required type="number" placeholder="Branch ID" class="rounded-md border border-line px-3 py-2 text-sm" />
          <select v-model="form.registration_channel" class="rounded-md border border-line px-3 py-2 text-sm">
            <option value="web">Web</option>
            <option value="reception">Reception</option>
            <option value="import">Import</option>
          </select>
          <label class="flex items-center gap-2 text-sm sm:col-span-2">
            <input v-model="form.consent_data_processing" type="checkbox" />
            Data processing consent
          </label>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" class="rounded-md border border-line px-4 py-2 text-sm" @click="showRegister = false">Cancel</button>
          <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="membersStore.saving">
            {{ membersStore.saving ? 'Saving…' : 'Register' }}
          </button>
        </div>
      </form>
    </div>

    <div v-if="selectedId" class="fixed inset-0 z-50 flex items-center justify-center bg-ink/45 p-4" @click.self="selectedId = null">
      <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-md border border-line bg-white p-6 shadow-lg">
        <div class="mb-4 flex items-start justify-between gap-4">
          <div>
            <h2 class="font-serif text-xl font-bold">{{ membersStore.selectedMember?.full_name }}</h2>
            <p class="text-sm text-muted">{{ membersStore.selectedMember?.membership_id }}</p>
          </div>
          <button type="button" class="text-sm text-muted hover:text-ink" @click="selectedId = null">Close</button>
        </div>

        <div v-if="membersStore.selectedMember" class="grid gap-3 sm:grid-cols-2">
          <input v-model="editForm.phone" placeholder="Phone" class="rounded-md border border-line px-3 py-2 text-sm" />
          <input v-model="editForm.email" placeholder="Email" class="rounded-md border border-line px-3 py-2 text-sm" />
        </div>

        <section v-if="lifecycleState" class="mt-6 rounded-md border border-line p-4">
          <h3 class="text-sm font-semibold">Lifecycle</h3>
          <p class="mt-1 text-xs text-muted">
            Stage: <span class="capitalize">{{ lifecycleState.current?.stage }}</span> ·
            Status: <span class="capitalize">{{ lifecycleState.current?.status }}</span>
          </p>

          <form class="mt-3 grid gap-2 sm:grid-cols-2" @submit.prevent="submitLifecycleTransition">
            <select v-model="lifecycleForm.to_stage" class="rounded-md border border-line px-3 py-2 text-sm">
              <option value="">Keep current stage</option>
              <option v-for="stage in lifecycleState.config?.stages || []" :key="stage" :value="stage">{{ stage }}</option>
            </select>
            <select v-model="lifecycleForm.to_status" class="rounded-md border border-line px-3 py-2 text-sm">
              <option value="">Keep current status</option>
              <option v-for="status in lifecycleState.config?.statuses || []" :key="status" :value="status">{{ status }}</option>
            </select>
            <input v-model="lifecycleForm.effective_date" type="date" required class="rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="lifecycleForm.reason" required placeholder="Reason" class="rounded-md border border-line px-3 py-2 text-sm sm:col-span-2" />
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white sm:col-span-2" :disabled="lifecycleSaving">
              Record lifecycle transition
            </button>
          </form>

          <ul v-if="lifecycleState.history?.length" class="mt-4 space-y-2 text-xs text-muted">
            <li v-for="entry in lifecycleState.history.slice(0, 5)" :key="entry.id">
              {{ entry.effective_date }} — {{ entry.stage }}/{{ entry.status }}: {{ entry.reason }}
            </li>
          </ul>
        </section>

        <div class="mt-6 flex justify-between gap-2">
          <button
            type="button"
            class="rounded-md border border-danger/40 px-4 py-2 text-sm text-danger"
            :disabled="membersStore.saving || membersStore.selectedMember?.membership_status === 'archived'"
            @click="archiveSelected"
          >
            Archive
          </button>
          <button type="button" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="membersStore.saving" @click="saveSelected">
            Save changes
          </button>
        </div>
      </div>
    </div>

    <div v-if="duplicateReviewId" class="fixed inset-0 z-50 flex items-center justify-center bg-ink/45 p-4" @click.self="closeDuplicateReview">
      <div class="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-md border border-line bg-white p-6 shadow-lg">
        <div class="mb-4 flex items-start justify-between gap-4">
          <div>
            <h2 class="font-serif text-xl font-bold">Review duplicate members</h2>
            <p v-if="duplicatesStore.comparison?.flag" class="text-sm text-muted">
              {{ duplicatesStore.comparison.flag.confidence }} confidence · {{ duplicatesStore.comparison.flag.match_reason }}
            </p>
          </div>
          <button type="button" class="text-sm text-muted hover:text-ink" @click="closeDuplicateReview">Close</button>
        </div>

        <p v-if="duplicatesStore.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger">
          {{ duplicatesStore.error }}
        </p>

        <div v-if="duplicatesStore.comparison?.conflicts?.length" class="mb-4 rounded-md border border-warning/40 bg-warning/10 px-4 py-3 text-sm">
          <p class="font-semibold">Merge blocked</p>
          <ul class="mt-2 list-disc pl-5 text-xs">
            <li v-for="(conflict, index) in duplicatesStore.comparison.conflicts" :key="index">{{ conflict.message }}</li>
          </ul>
        </div>

        <div v-if="duplicatesStore.comparison" class="space-y-4">
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="rounded-md border border-line p-3">
              <input v-model="mergeForm.survivor_id" type="radio" :value="duplicatesStore.comparison.member_a.id" class="mr-2" />
              Survivor: {{ duplicatesStore.comparison.member_a.full_name }} ({{ duplicatesStore.comparison.member_a.membership_id }})
            </label>
            <label class="rounded-md border border-line p-3">
              <input v-model="mergeForm.survivor_id" type="radio" :value="duplicatesStore.comparison.member_b.id" class="mr-2" />
              Survivor: {{ duplicatesStore.comparison.member_b.full_name }} ({{ duplicatesStore.comparison.member_b.membership_id }})
            </label>
          </div>

          <div class="overflow-hidden rounded-md border border-line">
            <table class="min-w-full text-left text-xs">
              <thead class="border-b border-line bg-canvas/60 uppercase tracking-wide text-muted">
                <tr>
                  <th class="px-3 py-2">Field</th>
                  <th class="px-3 py-2">Member A</th>
                  <th class="px-3 py-2">Member B</th>
                  <th class="px-3 py-2">Keep</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="field in duplicatesStore.comparison.mergeable_fields" :key="field" class="border-b border-line last:border-b-0">
                  <td class="px-3 py-2 font-medium">{{ field }}</td>
                  <td class="px-3 py-2">{{ displayFieldValue(duplicatesStore.comparison.member_a.fields[field]) }}</td>
                  <td class="px-3 py-2">{{ displayFieldValue(duplicatesStore.comparison.member_b.fields[field]) }}</td>
                  <td class="px-3 py-2">
                    <select v-model="mergeForm.field_resolutions[field]" class="rounded-md border border-line px-2 py-1">
                      <option value="survivor">Survivor</option>
                      <option value="merged">Other</option>
                    </select>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="flex justify-end gap-2">
            <button type="button" class="rounded-md border border-line px-4 py-2 text-sm" :disabled="duplicatesStore.saving" @click="dismissDuplicate">
              Dismiss
            </button>
            <button
              type="button"
              class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white"
              :disabled="duplicatesStore.saving || duplicatesStore.comparison.conflicts?.length"
              @click="confirmMerge"
            >
              {{ duplicatesStore.saving ? 'Merging…' : 'Confirm merge' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useMembersStore } from '../stores/members'
import { useProfileStore } from '../stores/profile'
import { useDuplicatesStore } from '../stores/duplicates'
import lifecycleApi from '../api/lifecycle'

const membersStore = useMembersStore()
const profileStore = useProfileStore()
const duplicatesStore = useDuplicatesStore()
const pendingReviews = ref([])
const duplicateFlags = ref([])
const duplicateReviewId = ref(null)
const lifecycleState = ref(null)
const lifecycleSaving = ref(false)
const drawerOpen = ref(false)
const showRegister = ref(false)
const selectedId = ref(null)
const duplicateWarning = ref(null)

const form = reactive({
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  branch_id: '',
  registration_channel: 'reception',
  consent_data_processing: true,
  consent_directory: false,
})

const editForm = reactive({
  phone: '',
  email: '',
})

const lifecycleForm = reactive({
  to_stage: '',
  to_status: '',
  effective_date: new Date().toISOString().slice(0, 10),
  reason: '',
})

const mergeForm = reactive({
  survivor_id: null,
  merged_member_id: null,
  field_resolutions: {},
})

const submitRegistration = async () => {
  duplicateWarning.value = null
  const result = await membersStore.createMember({ ...form })
  if (result.duplicate) {
    duplicateWarning.value = result.duplicate
    return
  }
  showRegister.value = false
}

const openMember = async (id) => {
  selectedId.value = id
  await membersStore.fetchMember(id)
  editForm.phone = membersStore.selectedMember?.phone || ''
  editForm.email = membersStore.selectedMember?.email || ''
  try {
    const response = await lifecycleApi.getLifecycle(id)
    lifecycleState.value = response.data?.data ?? null
  } catch {
    lifecycleState.value = null
  }
}

const submitLifecycleTransition = async () => {
  if (!selectedId.value) return
  lifecycleSaving.value = true
  try {
    const payload = {
      effective_date: lifecycleForm.effective_date,
      reason: lifecycleForm.reason,
    }
    if (lifecycleForm.to_stage) payload.to_stage = lifecycleForm.to_stage
    if (lifecycleForm.to_status) payload.to_status = lifecycleForm.to_status
    await lifecycleApi.transition(selectedId.value, payload)
    await openMember(selectedId.value)
    await membersStore.fetchMembers()
  } finally {
    lifecycleSaving.value = false
  }
}

const saveSelected = async () => {
  if (!selectedId.value) return
  await membersStore.updateMember(selectedId.value, { ...editForm })
}

const archiveSelected = async () => {
  if (!selectedId.value) return
  await membersStore.archiveMember(selectedId.value, 'Archived from member profile screen')
  selectedId.value = null
}

const displayValue = (value) => {
  if (value && typeof value === 'object' && 'value' in value) return value.value
  return value
}

const displayFieldValue = (value) => {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'object') return JSON.stringify(value)
  return value
}

const loadDuplicateFlags = async () => {
  await duplicatesStore.fetchFlags()
  duplicateFlags.value = duplicatesStore.flags
}

const openDuplicateReview = async (flagId) => {
  duplicateReviewId.value = flagId
  await duplicatesStore.loadComparison(flagId)
  const comparison = duplicatesStore.comparison
  if (!comparison) return

  mergeForm.survivor_id = comparison.member_a.id
  mergeForm.field_resolutions = {}
  for (const field of comparison.mergeable_fields || []) {
    mergeForm.field_resolutions[field] = 'survivor'
  }
}

const closeDuplicateReview = () => {
  duplicateReviewId.value = null
  duplicatesStore.comparison = null
}

const dismissDuplicate = async () => {
  if (!duplicateReviewId.value) return
  await duplicatesStore.dismiss(duplicateReviewId.value)
  closeDuplicateReview()
  await loadDuplicateFlags()
}

const confirmMerge = async () => {
  const comparison = duplicatesStore.comparison
  if (!comparison || !mergeForm.survivor_id) return

  const survivorId = mergeForm.survivor_id
  const mergedId = survivorId === comparison.member_a.id
    ? comparison.member_b.id
    : comparison.member_a.id

  await duplicatesStore.merge({
    survivor_id: survivorId,
    merged_member_id: mergedId,
    field_resolutions: { ...mergeForm.field_resolutions },
    flag_id: duplicateReviewId.value,
  })

  closeDuplicateReview()
  await loadDuplicateFlags()
  await membersStore.fetchMembers()
}

const loadPendingReviews = async () => {
  try {
    pendingReviews.value = await profileStore.fetchPendingReviews()
  } catch {
    pendingReviews.value = []
  }
}

const approveReview = async (id) => {
  await profileStore.approveChange(id)
  await loadPendingReviews()
}

const rejectReview = async (id) => {
  await profileStore.rejectChange(id, 'Rejected from members screen')
  await loadPendingReviews()
}

onMounted(async () => {
  await membersStore.fetchMembers()
  await loadPendingReviews()
  await loadDuplicateFlags()
})
</script>
