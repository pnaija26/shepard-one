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
            <p class="truncate text-sm font-semibold text-ink">Report schedules</p>
            <p class="truncate text-xs text-muted">Recurring generation and permission-checked delivery</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Decisions</p>
          <h1 class="font-serif text-3xl font-bold">Scheduled report distribution</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createSchedule">
            <h2 class="font-semibold">Create schedule</h2>
            <input v-model="form.name" required placeholder="Schedule name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.report_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="standard">Standard report</option>
              <option value="custom">Custom report</option>
            </select>
            <input v-if="form.report_type === 'standard'" v-model="form.report_key" placeholder="Report key (e.g. membership)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-else v-model.number="form.custom_report_id" type="number" placeholder="Custom report ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.format" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="format in store.catalog?.formats || []" :key="format" :value="format">{{ format }}</option>
            </select>
            <select v-model="form.delivery_channel" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="channel in store.catalog?.delivery_channels || []" :key="channel" :value="channel">{{ channel }}</option>
            </select>
            <select v-model="form.recurrence" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="recurrence in store.catalog?.recurrences || []" :key="recurrence" :value="recurrence">{{ recurrence }}</option>
            </select>
            <input v-model="form.timezone" placeholder="Timezone" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.recipient_user_ids" placeholder="Recipient user IDs (comma-separated)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.classification" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="classification in store.catalog?.classifications || []" :key="classification" :value="classification">{{ classification }}</option>
            </select>
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving">Save schedule</button>
          </form>

          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Saved schedules</h2>
            <ul class="space-y-2 text-sm">
              <li v-for="schedule in store.schedules" :key="schedule.id" class="rounded-md border border-line px-3 py-2">
                <p class="font-medium">{{ schedule.name }}</p>
                <p class="text-xs text-muted">{{ schedule.recurrence }} · next {{ schedule.next_run_at }} · {{ schedule.status }}</p>
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
import { useReportSchedulesStore } from '../stores/reportSchedules'

const store = useReportSchedulesStore()
const drawerOpen = ref(false)

const form = reactive({
  name: '',
  report_type: 'standard',
  report_key: 'membership',
  custom_report_id: '',
  format: 'csv',
  delivery_channel: 'in_app',
  recurrence: 'daily',
  timezone: 'UTC',
  classification: 'internal',
  recipient_user_ids: '',
})

async function createSchedule() {
  const recipientIds = form.recipient_user_ids
    .split(',')
    .map((value) => value.trim())
    .filter(Boolean)
    .map((value) => Number(value))

  const payload = {
    name: form.name,
    report_type: form.report_type,
    format: form.format,
    delivery_channel: form.delivery_channel,
    recurrence: form.recurrence,
    timezone: form.timezone,
    classification: form.classification,
    recipient_user_ids: recipientIds,
  }

  if (form.report_type === 'standard') {
    payload.report_key = form.report_key
  } else {
    payload.custom_report_id = form.custom_report_id ? Number(form.custom_report_id) : undefined
  }

  await store.createSchedule(payload)
}

onMounted(async () => {
  await Promise.all([store.loadCatalog(), store.loadSchedules()])
})
</script>
