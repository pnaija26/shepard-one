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
            <p class="truncate text-sm font-semibold text-ink">Workflows</p>
            <p class="truncate text-xs text-muted">Design, publish, and execute reusable processes</p>
          </div>
          <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="store.saving" @click="runDeadlines">
            Process deadlines
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Automation</p>
          <h1 class="font-serif text-3xl font-bold">Workflow designer</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>
        <p v-if="store.deadlineResult" class="mb-4 rounded-md border border-line bg-white px-4 py-3 text-sm text-muted">
          Deadlines: reminded {{ store.deadlineResult.reminded }}, escalated {{ store.deadlineResult.escalated }}, skipped {{ store.deadlineResult.skipped }}
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createWorkflow">
            <h2 class="font-semibold">Create workflow</h2>
            <input v-model="form.name" required placeholder="Name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.branch_id" type="number" placeholder="Branch ID (optional)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.migration_policy" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="keep_locked">Keep locked (existing instances stay)</option>
              <option value="migrate_pending">Migrate pending instances</option>
            </select>
            <textarea v-model="form.definitionJson" rows="14" required class="w-full rounded-md border border-line px-3 py-2 font-mono text-xs"></textarea>
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving">Create draft</button>
          </form>

          <section class="space-y-6">
            <div class="rounded-md border border-line bg-white p-5">
              <h2 class="mb-3 font-semibold">Workflows</h2>
              <ul class="space-y-2 text-sm">
                <li v-for="item in store.workflows" :key="item.id" class="rounded-md border border-line p-3">
                  <button type="button" class="w-full text-left" @click="openWorkflow(item.id)">
                    <p class="font-medium">{{ item.name }}</p>
                    <p class="text-xs text-muted">{{ item.status }} · published v{{ item.current_version || 0 }} · draft v{{ item.draft_version }}</p>
                  </button>
                </li>
              </ul>
            </div>

            <section v-if="store.selected" class="space-y-3 rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="font-semibold">{{ store.selected.name }}</h2>
              <p class="text-xs text-muted">
                {{ store.selected.status }} · current v{{ store.selected.current_version }} · draft v{{ store.selected.draft_version }}
                · policy {{ store.selected.migration_policy }}
              </p>

              <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="visualize">Visualize</button>
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="validate">Validate</button>
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="runTest">Test sample</button>
                <button type="button" class="rounded-md bg-brand px-2 py-1 text-xs font-semibold text-white" :disabled="store.saving" @click="publish">Publish</button>
              </div>

              <div v-if="store.selected.status === 'published'" class="space-y-2 border-t border-line pt-3">
                <h3 class="font-medium">Start instance</h3>
                <input v-model="startForm.assignee_id" type="number" placeholder="Assignee user ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <input v-model="startForm.amount" type="number" placeholder="Context amount" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="startInstance">Start</button>
              </div>

              <div v-if="store.visualization" class="rounded-md bg-canvas p-3 text-xs">
                <p class="mb-1 font-medium text-ink">Graph</p>
                <p>Nodes: {{ store.visualization.nodes?.map((n) => n.id).join(', ') }}</p>
                <p>Edges: {{ store.visualization.edges?.map((e) => `${e.from}→${e.to}`).join(', ') }}</p>
                <p>Valid: {{ store.visualization.validation?.valid ? 'yes' : 'no' }}</p>
              </div>

              <pre v-if="store.validation" class="overflow-auto rounded-md bg-canvas p-2 text-xs">{{ store.validation }}</pre>
              <pre v-if="store.testResult" class="overflow-auto rounded-md bg-canvas p-2 text-xs">{{ store.testResult }}</pre>

              <div v-if="store.selected.versions?.length" class="border-t border-line pt-3">
                <h3 class="mb-2 font-medium">Versions & test evidence</h3>
                <ul class="space-y-2 text-xs text-muted">
                  <li v-for="version in store.selected.versions" :key="version.id">
                    v{{ version.version }} · {{ version.status }}
                    <span v-if="version.tests?.length"> · {{ version.tests.length }} test(s)</span>
                  </li>
                </ul>
              </div>
            </section>

            <section class="rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="mb-3 font-semibold">Instances</h2>
              <ul class="mb-3 space-y-2">
                <li v-for="item in store.instances" :key="item.id" class="rounded-md border border-line p-3">
                  <button type="button" class="w-full text-left" @click="openInstance(item.id)">
                    <p class="font-medium">{{ item.reference }} · {{ item.current_state }}</p>
                    <p class="text-xs text-muted">{{ item.status }} · {{ item.assignee?.name || 'unassigned' }}</p>
                  </button>
                </li>
              </ul>

              <div v-if="store.selectedInstance" class="space-y-2 border-t border-line pt-3">
                <h3 class="font-medium">{{ store.selectedInstance.reference }}</h3>
                <p class="text-xs text-muted">{{ store.selectedInstance.status }} · {{ store.selectedInstance.current_state }}</p>
                <select v-model="actForm.decision" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                  <option value="approve">Approve</option>
                  <option value="reject">Reject</option>
                  <option value="return">Return</option>
                  <option value="complete">Complete</option>
                  <option value="reassign">Reassign</option>
                </select>
                <input v-if="actForm.decision === 'reassign'" v-model="actForm.assignee_id" type="number" placeholder="New assignee ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <textarea v-model="actForm.comment" rows="2" placeholder="Comment" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
                <button type="button" class="rounded-md bg-brand px-3 py-1.5 text-xs font-semibold text-white" :disabled="store.saving" @click="actOnInstance">Submit decision</button>
                <ul v-if="store.selectedInstance.events?.length" class="space-y-1 text-xs text-muted">
                  <li v-for="event in store.selectedInstance.events" :key="event.id">
                    {{ event.event_type }} · {{ event.decision || '—' }} · {{ event.comment || '' }}
                  </li>
                </ul>
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
import { useWorkflowsStore } from '../stores/workflows'

const drawerOpen = ref(false)
const store = useWorkflowsStore()

const defaultDefinition = {
  trigger: { type: 'manual' },
  conditions: [{ field: 'amount', operator: 'gte', value: 0 }],
  states: [
    { key: 'start', type: 'start', label: 'Start' },
    { key: 'review', type: 'approval', label: 'Review' },
    { key: 'approved', type: 'end', label: 'Approved' },
    { key: 'rejected', type: 'end', label: 'Rejected' },
  ],
  transitions: [
    { from: 'start', to: 'review', action: 'submit' },
    { from: 'review', to: 'approved', on: 'approve' },
    { from: 'review', to: 'rejected', on: 'reject' },
  ],
  assignments: [{ state: 'review', permission: 'tasks.work' }],
  approvals: [{ state: 'review', quorum: 1 }],
  rejection: { target_state: 'rejected' },
  escalation: { after_hours: 48, to_permission: 'tasks.manage', max_loops: 3 },
  notifications: [{ state: 'approved', channel: 'in_app', template: 'workflow.approved' }],
  deadlines: [{ state: 'review', hours: 24 }],
  reminders: [{ state: 'review', every_hours: 12, max_count: 3 }],
  end_states: ['approved', 'rejected'],
}

const form = reactive({
  name: '',
  branch_id: '',
  migration_policy: 'keep_locked',
  definitionJson: JSON.stringify(defaultDefinition, null, 2),
})

const startForm = reactive({
  assignee_id: '',
  amount: '25',
})

const actForm = reactive({
  decision: 'approve',
  comment: '',
  assignee_id: '',
})

async function createWorkflow() {
  const definition = JSON.parse(form.definitionJson)
  await store.createWorkflow({
    name: form.name,
    branch_id: form.branch_id ? Number(form.branch_id) : undefined,
    migration_policy: form.migration_policy,
    definition,
  })
  form.name = ''
}

async function openWorkflow(id) {
  await store.selectWorkflow(id)
  await store.fetchInstances({ workflow_id: id })
}

async function visualize() {
  if (!store.selected) return
  await store.visualize(store.selected.id)
}

async function validate() {
  if (!store.selected) return
  await store.validate(store.selected.id)
}

async function runTest() {
  if (!store.selected) return
  await store.test(store.selected.id, {
    sample: { amount: 25, _next_action: 'approve' },
  })
}

async function publish() {
  if (!store.selected) return
  await store.publish(store.selected.id, {
    migration_policy: store.selected.migration_policy || 'keep_locked',
  })
}

async function startInstance() {
  if (!store.selected) return
  await store.startInstance(store.selected.id, {
    trigger_type: 'manual',
    branch_id: store.selected.branch_id || Number(form.branch_id) || undefined,
    assignee_id: startForm.assignee_id ? Number(startForm.assignee_id) : undefined,
    idempotency_key: `ui-${Date.now()}`,
    context: { amount: Number(startForm.amount || 0) },
  })
}

async function openInstance(id) {
  await store.selectInstance(id)
}

async function actOnInstance() {
  if (!store.selectedInstance) return
  await store.actOnInstance(store.selectedInstance.id, {
    decision: actForm.decision,
    comment: actForm.comment || undefined,
    assignee_id: actForm.decision === 'reassign' && actForm.assignee_id
      ? Number(actForm.assignee_id)
      : undefined,
  })
}

async function runDeadlines() {
  await store.processDeadlines()
}

onMounted(async () => {
  await store.fetchWorkflows()
  await store.fetchInstances()
})
</script>
