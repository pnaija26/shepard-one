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
            <p class="truncate text-sm font-semibold text-ink">Communications</p>
            <p class="truncate text-xs text-muted">Send permission-aware multi-channel messages</p>
          </div>
          <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="store.saving" @click="runDue">
            Process due
          </button>
          <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="store.saving" @click="runRetries">
            Process retries
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Engage</p>
          <h1 class="font-serif text-3xl font-bold">Send messages</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>
        <p v-if="store.processResult" class="mb-4 rounded-md border border-line bg-white px-4 py-3 text-sm text-muted">
          Last run: {{ JSON.stringify(store.processResult) }}
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="send">
            <h2 class="font-semibold">New communication</h2>
            <input v-model="form.name" required placeholder="Name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.subject" required placeholder="Subject" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <textarea v-model="form.body" required rows="4" placeholder="Body" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
            <input v-model="form.branch_id" type="number" placeholder="Branch ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.purpose" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="announcement">Announcement</option>
              <option value="pastoral">Pastoral</option>
              <option value="operational">Operational</option>
              <option value="engagement">Engagement</option>
              <option value="emergency">Emergency</option>
              <option value="system">System</option>
            </select>
            <select v-model="form.schedule_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="immediate">Immediate</option>
              <option value="scheduled">Scheduled</option>
              <option value="recurring">Recurring</option>
              <option value="event">Event-based</option>
              <option value="workflow">Workflow-based</option>
            </select>
            <input v-if="form.schedule_type === 'scheduled' || form.schedule_type === 'recurring'" v-model="form.scheduled_at" type="datetime-local" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <div class="flex flex-wrap gap-3 text-sm">
              <label v-for="channel in channelOptions" :key="channel" class="flex items-center gap-1.5">
                <input v-model="form.channels" type="checkbox" :value="channel" class="rounded border-line" />
                {{ channel }}
              </label>
            </div>
            <select v-model="form.audience_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="members">Specific members</option>
              <option value="branch">Branch</option>
              <option value="role">Role</option>
              <option value="group">Group</option>
            </select>
            <input v-if="form.audience_type === 'members'" v-model="form.member_ids" placeholder="Member IDs (comma-separated)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-if="form.audience_type === 'branch'" v-model="form.audience_branch_id" type="number" placeholder="Audience branch ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-if="form.audience_type === 'role'" v-model="form.role_id" type="number" placeholder="Role ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-if="form.audience_type === 'group'" v-model="form.group_id" type="number" placeholder="Group ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving">Send</button>
          </form>

          <section class="space-y-6">
            <div class="rounded-md border border-line bg-white p-5">
              <h2 class="mb-3 font-semibold">Recent sends</h2>
              <ul class="space-y-2 text-sm">
                <li v-for="item in store.items" :key="item.id" class="rounded-md border border-line p-3">
                  <button type="button" class="w-full text-left" @click="openItem(item.id)">
                    <p class="font-medium">{{ item.reference }} · {{ item.name }}</p>
                    <p class="text-xs text-muted">
                      {{ item.status }} · {{ item.purpose }} · sent {{ item.sent_count }}/{{ item.queued_count }}
                      · skipped {{ item.skipped_count }} · failed {{ item.failed_count }}
                    </p>
                  </button>
                </li>
              </ul>
            </div>

            <section v-if="store.selected" class="space-y-3 rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="font-semibold">{{ store.selected.reference }}</h2>
              <p class="text-xs text-muted">{{ store.selected.subject }} · {{ store.selected.schedule_type }} · {{ store.selected.status }}</p>
              <p class="text-xs text-muted">Channels: {{ (store.selected.channels || []).join(', ') }}</p>
              <p class="text-xs text-muted">Body on file: {{ store.selected.body_present ? 'yes (hidden)' : 'no' }}</p>

              <button
                v-if="['queued', 'processing'].includes(store.selected.status)"
                type="button"
                class="rounded-md border border-line px-2 py-1 text-xs"
                :disabled="store.saving"
                @click="cancelSelected"
              >
                Cancel
              </button>

              <div v-if="store.selected.deliveries?.length" class="space-y-1 border-t border-line pt-3 text-xs text-muted">
                <h3 class="mb-1 text-sm font-medium text-ink">Deliveries</h3>
                <p v-for="row in store.selected.deliveries" :key="row.id">
                  #{{ row.member_id }} · {{ row.channel }} · {{ row.status }}
                  <span v-if="row.skip_reason">({{ row.skip_reason }})</span>
                  · {{ row.destination || '—' }}
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
import { useCommunicationsStore } from '../stores/communications'

const store = useCommunicationsStore()
const drawerOpen = ref(false)
const channelOptions = ['email', 'sms', 'push', 'in_app', 'external']

const form = reactive({
  name: '',
  subject: '',
  body: '',
  branch_id: '',
  purpose: 'announcement',
  schedule_type: 'immediate',
  scheduled_at: '',
  channels: ['email', 'in_app'],
  audience_type: 'members',
  member_ids: '',
  audience_branch_id: '',
  role_id: '',
  group_id: '',
})

function audienceParams() {
  if (form.audience_type === 'members') {
    return {
      member_ids: form.member_ids
        .split(',')
        .map((id) => Number(id.trim()))
        .filter((id) => id > 0),
    }
  }
  if (form.audience_type === 'branch') {
    return { branch_id: Number(form.audience_branch_id || form.branch_id) }
  }
  if (form.audience_type === 'role') {
    return { role_id: Number(form.role_id) }
  }
  return { group_id: Number(form.group_id) }
}

async function send() {
  if (!form.channels.length) {
    store.error = 'Select at least one channel'
    return
  }

  await store.create({
    name: form.name,
    subject: form.subject,
    body: form.body,
    branch_id: form.branch_id ? Number(form.branch_id) : null,
    purpose: form.purpose,
    schedule_type: form.schedule_type,
    scheduled_at: form.scheduled_at || null,
    channels: [...form.channels],
    audience_type: form.audience_type,
    audience_params: audienceParams(),
  })
}

async function openItem(id) {
  await store.select(id)
}

async function cancelSelected() {
  if (!store.selected) return
  await store.cancel(store.selected.id)
}

async function runDue() {
  await store.processDue()
}

async function runRetries() {
  await store.processRetries()
}

onMounted(async () => {
  await store.fetchItems()
})
</script>
