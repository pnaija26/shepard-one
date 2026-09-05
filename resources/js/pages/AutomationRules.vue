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
            <p class="truncate text-sm font-semibold text-ink">Automation rules</p>
            <p class="truncate text-xs text-muted">Configure event-driven tasks, notifications, and workflows</p>
          </div>
          <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="store.saving" @click="runRetries">
            Process retries
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Automation</p>
          <h1 class="font-serif text-3xl font-bold">Event rules</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>
        <p v-if="store.evaluateResult" class="mb-4 rounded-md border border-line bg-white px-4 py-3 text-sm text-muted">
          Evaluate: executed {{ store.evaluateResult.executed }}, skipped {{ store.evaluateResult.skipped }}, failed {{ store.evaluateResult.failed }}
        </p>
        <p v-if="store.retryResult" class="mb-4 rounded-md border border-line bg-white px-4 py-3 text-sm text-muted">
          Retries: retried {{ store.retryResult.retried }}, executed {{ store.retryResult.executed }}, quarantined {{ store.retryResult.quarantined }}
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createRule">
            <h2 class="font-semibold">Create rule</h2>
            <input v-model="form.name" required placeholder="Name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.branch_id" type="number" placeholder="Branch ID (optional)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.event_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="event in eventTypes" :key="event" :value="event">{{ event }}</option>
            </select>
            <select v-model="form.action_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="create_task">Create task</option>
              <option value="send_notification">Send notification</option>
              <option value="start_workflow">Start workflow</option>
              <option value="emit_event">Emit event</option>
              <option value="log_only">Log only</option>
            </select>
            <textarea v-model="form.actionParamsJson" rows="4" required class="w-full rounded-md border border-line px-3 py-2 font-mono text-xs" placeholder="Action params JSON"></textarea>
            <textarea v-model="form.conditionsJson" rows="3" class="w-full rounded-md border border-line px-3 py-2 font-mono text-xs" placeholder="Conditions JSON array"></textarea>
            <div class="grid grid-cols-2 gap-2">
              <input v-model.number="form.priority" type="number" min="1" max="100" placeholder="Priority" class="rounded-md border border-line px-3 py-2 text-sm" />
              <select v-model="form.stop_behavior" class="rounded-md border border-line px-3 py-2 text-sm">
                <option value="continue">Continue</option>
                <option value="stop_on_match">Stop on match</option>
                <option value="stop_on_success">Stop on success</option>
              </select>
            </div>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="form.requires_consent" type="checkbox" class="rounded border-line" />
              Requires consent
            </label>
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving">Create draft</button>
          </form>

          <section class="space-y-6">
            <div class="rounded-md border border-line bg-white p-5">
              <h2 class="mb-3 font-semibold">Rules</h2>
              <ul class="space-y-2 text-sm">
                <li v-for="item in store.rules" :key="item.id" class="rounded-md border border-line p-3">
                  <button type="button" class="w-full text-left" @click="openRule(item.id)">
                    <p class="font-medium">{{ item.name }}</p>
                    <p class="text-xs text-muted">
                      {{ item.status }} · {{ item.event_type }} → {{ item.action_type }}
                      · {{ item.enabled ? 'enabled' : 'disabled' }}
                    </p>
                  </button>
                </li>
              </ul>
            </div>

            <section v-if="store.selected" class="space-y-3 rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="font-semibold">{{ store.selected.name }}</h2>
              <p class="text-xs text-muted">
                {{ store.selected.status }} · published v{{ store.selected.current_version || 0 }}
                · draft v{{ store.selected.draft_version }} · priority {{ store.selected.priority }}
              </p>

              <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="validate">Validate</button>
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="simulate">Simulate</button>
                <button type="button" class="rounded-md bg-brand px-2 py-1 text-xs font-semibold text-white" :disabled="store.saving" @click="publish">Publish</button>
                <button
                  type="button"
                  class="rounded-md border border-line px-2 py-1 text-xs"
                  :disabled="store.saving"
                  @click="toggleEnabled"
                >
                  {{ store.selected.enabled ? 'Disable' : 'Enable' }}
                </button>
              </div>

              <div v-if="store.validation" class="rounded-md border border-line bg-canvas p-3 text-xs">
                <p class="font-medium text-ink">Validation: {{ store.validation.validation?.valid ? 'valid' : 'invalid' }}</p>
                <pre class="mt-1 overflow-auto whitespace-pre-wrap">{{ JSON.stringify(store.validation.validation, null, 2) }}</pre>
              </div>

              <div v-if="store.simulation" class="rounded-md border border-line bg-canvas p-3 text-xs">
                <p class="font-medium text-ink">Simulation: {{ store.simulation.passed ? 'would execute' : 'no match' }}</p>
                <pre class="mt-1 overflow-auto whitespace-pre-wrap">{{ JSON.stringify(store.simulation.result, null, 2) }}</pre>
              </div>

              <div class="space-y-2 border-t border-line pt-3">
                <h3 class="font-medium">Evaluate sample event</h3>
                <input v-model="evaluateForm.event_key" placeholder="Event key" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <textarea v-model="evaluateForm.payloadJson" rows="4" class="w-full rounded-md border border-line px-3 py-2 font-mono text-xs"></textarea>
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="evaluate">Evaluate</button>
              </div>

              <div v-if="store.selected.evaluations?.length" class="space-y-1 border-t border-line pt-3 text-xs text-muted">
                <h3 class="mb-1 text-sm font-medium text-ink">Recent evaluations</h3>
                <p v-for="row in store.selected.evaluations" :key="row.id">
                  {{ row.outcome }}{{ row.skip_reason ? ` (${row.skip_reason})` : '' }}
                  · {{ row.event_key }} · {{ row.evaluated_at }}
                </p>
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
import { useAutomationRulesStore } from '../stores/automationRules'

const store = useAutomationRulesStore()
const drawerOpen = ref(false)

const eventTypes = [
  'attendance.exception_detected',
  'attendance.recorded',
  'member.birthday',
  'member.anniversary',
  'team.roster_published',
  'team.report_submitted',
  'welfare.request_submitted',
  'welfare.follow_up_due',
]

const form = reactive({
  name: '',
  branch_id: '',
  event_type: 'attendance.exception_detected',
  action_type: 'create_task',
  actionParamsJson: JSON.stringify({
    title: 'Follow up',
    department: 'pastoral',
    priority: 'high',
  }, null, 2),
  conditionsJson: JSON.stringify([
    { field: 'consecutive_days', operator: 'gte', value: 3 },
  ], null, 2),
  priority: 80,
  stop_behavior: 'stop_on_match',
  requires_consent: false,
})

const evaluateForm = reactive({
  event_key: 'sample-event-1',
  payloadJson: JSON.stringify({
    consecutive_days: 4,
    branch_id: null,
    consent: true,
  }, null, 2),
})

async function createRule() {
  let action_params = {}
  let conditions = []
  try {
    action_params = JSON.parse(form.actionParamsJson || '{}')
    conditions = JSON.parse(form.conditionsJson || '[]')
  } catch {
    store.error = 'Action params and conditions must be valid JSON'
    return
  }

  await store.createRule({
    name: form.name,
    branch_id: form.branch_id ? Number(form.branch_id) : null,
    event_type: form.event_type,
    action_type: form.action_type,
    action_params,
    conditions,
    priority: form.priority,
    stop_behavior: form.stop_behavior,
    requires_consent: form.requires_consent,
  })
}

async function openRule(id) {
  await store.selectRule(id)
}

async function validate() {
  if (!store.selected) return
  await store.validate(store.selected.id)
}

async function simulate() {
  if (!store.selected) return
  let sample = {}
  try {
    sample = JSON.parse(evaluateForm.payloadJson || '{}')
  } catch {
    store.error = 'Sample payload must be valid JSON'
    return
  }
  await store.simulate(store.selected.id, { sample })
}

async function publish() {
  if (!store.selected) return
  await store.publish(store.selected.id)
}

async function toggleEnabled() {
  if (!store.selected) return
  await store.setEnabled(store.selected.id, !store.selected.enabled)
}

async function evaluate() {
  if (!store.selected) return
  let payload = {}
  try {
    payload = JSON.parse(evaluateForm.payloadJson || '{}')
  } catch {
    store.error = 'Evaluate payload must be valid JSON'
    return
  }
  payload.event_key = evaluateForm.event_key
  if (store.selected.branch_id && payload.branch_id == null) {
    payload.branch_id = store.selected.branch_id
  }
  await store.evaluate({
    event_type: store.selected.event_type,
    payload,
  })
  await store.selectRule(store.selected.id)
}

async function runRetries() {
  await store.processRetries()
}

onMounted(async () => {
  await store.fetchRules()
})
</script>
