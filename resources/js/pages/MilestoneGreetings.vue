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
            <p class="truncate text-sm font-semibold text-ink">Milestone greetings</p>
            <p class="truncate text-xs text-muted">Birthdays and anniversaries — once per period</p>
          </div>
          <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="store.saving" @click="runProcess">
            Process today
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Engage</p>
          <h1 class="font-serif text-3xl font-bold">Greetings</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>
        <p v-if="store.processResult" class="mb-4 rounded-md border border-line bg-white px-4 py-3 text-sm text-muted">
          Sent {{ store.processResult.sent }}, skipped {{ store.processResult.skipped }}, failed {{ store.processResult.failed }}
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="saveConfig">
            <h2 class="font-semibold">Greeting config</h2>
            <select v-model="form.milestone_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
            </select>
            <input v-model="form.message_template_id" type="number" required placeholder="Published template ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.branch_id" type="number" placeholder="Branch ID (optional)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <div class="flex flex-wrap gap-3 text-sm">
              <label v-for="channel in channelOptions" :key="channel" class="flex items-center gap-1.5">
                <input v-model="form.channels" type="checkbox" :value="channel" class="rounded border-line" />
                {{ channel }}
              </label>
            </div>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="form.enabled" type="checkbox" class="rounded border-line" />
              Enabled
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="form.team_alerts_enabled" type="checkbox" class="rounded border-line" />
              Team alerts
            </label>
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving">Save config</button>
          </form>

          <section class="space-y-6">
            <div class="rounded-md border border-line bg-white p-5">
              <h2 class="mb-3 font-semibold">Configs</h2>
              <ul class="space-y-2 text-sm">
                <li v-for="item in store.configs" :key="item.id" class="rounded-md border border-line p-3">
                  <p class="font-medium">{{ item.milestone_label }} · {{ item.enabled ? 'on' : 'off' }}</p>
                  <p class="text-xs text-muted">Template #{{ item.message_template_id }} · {{ (item.channels || []).join(', ') }}</p>
                </li>
              </ul>
            </div>

            <div class="rounded-md border border-line bg-white p-5">
              <h2 class="mb-3 font-semibold">Today’s list</h2>
              <p v-if="!store.today.length" class="text-sm text-muted">No approved milestone rows for today.</p>
              <ul class="space-y-2 text-sm">
                <li v-for="row in store.today" :key="row.member_id + row.milestone_type" class="rounded-md border border-line p-3">
                  <p class="font-medium">{{ row.preferred_name || row.first_name }} · {{ row.milestone_label }}</p>
                  <p class="text-xs text-muted">{{ row.occurrence_date }} · {{ row.years }} years · {{ row.branch_name }}</p>
                </li>
              </ul>
            </div>

            <div class="rounded-md border border-line bg-white p-5">
              <h2 class="mb-3 font-semibold">Recent evaluations</h2>
              <ul class="space-y-1 text-xs text-muted">
                <li v-for="row in store.evaluations" :key="row.id">
                  #{{ row.member_id }} · {{ row.milestone_type }} · {{ row.outcome }}
                  <span v-if="row.skip_reason">({{ row.skip_reason }})</span>
                </li>
              </ul>
            </div>
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
import { useMilestoneGreetingsStore } from '../stores/milestoneGreetings'

const store = useMilestoneGreetingsStore()
const drawerOpen = ref(false)
const types = ['birthday', 'wedding', 'membership', 'baptism', 'ordination', 'service']
const channelOptions = ['email', 'sms', 'in_app']

const form = reactive({
  milestone_type: 'birthday',
  message_template_id: '',
  branch_id: '',
  channels: ['email', 'in_app'],
  enabled: true,
  team_alerts_enabled: true,
})

async function saveConfig() {
  if (!form.channels.length) {
    store.error = 'Select at least one channel'
    return
  }
  await store.saveConfig({
    milestone_type: form.milestone_type,
    message_template_id: Number(form.message_template_id),
    branch_id: form.branch_id ? Number(form.branch_id) : null,
    channels: [...form.channels],
    enabled: form.enabled,
    team_alerts_enabled: form.team_alerts_enabled,
  })
}

async function runProcess() {
  await store.process({})
}

onMounted(async () => {
  await store.fetchConfigs()
  await store.fetchToday()
  await store.fetchEvaluations()
})
</script>
