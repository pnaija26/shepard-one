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
            <p class="truncate text-sm font-semibold text-ink">Community spaces</p>
            <p class="truncate text-xs text-muted">Moderated church, branch, team, and event conversations</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Engage</p>
          <h1 class="font-serif text-3xl font-bold">Spaces</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <div class="space-y-6">
            <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createSpace">
              <h2 class="font-semibold">Create space</h2>
              <input v-model="form.name" required placeholder="Name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <select v-model="form.space_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option v-for="t in spaceTypes" :key="t" :value="t">{{ t }}</option>
              </select>
              <input v-model="form.branch_id" type="number" placeholder="Branch ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <textarea v-model="form.description" rows="2" placeholder="Description" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving">Create</button>
            </form>

            <div class="rounded-md border border-line bg-white p-5">
              <h2 class="mb-3 font-semibold">Your spaces</h2>
              <ul class="space-y-2 text-sm">
                <li v-for="item in store.spaces" :key="item.id" class="rounded-md border border-line p-3">
                  <button type="button" class="w-full text-left" @click="openSpace(item.id)">
                    <p class="font-medium">{{ item.reference }} · {{ item.name }}</p>
                    <p class="text-xs text-muted">{{ item.space_type }} · retention {{ item.retention_days }}d</p>
                  </button>
                </li>
              </ul>
            </div>
          </div>

          <section v-if="store.selected" class="space-y-4 rounded-md border border-line bg-white p-5 text-sm">
            <div>
              <h2 class="font-semibold">{{ store.selected.name }}</h2>
              <p class="text-xs text-muted">{{ store.selected.reference }} · {{ store.selected.space_type }}</p>
            </div>

            <form class="flex flex-wrap gap-2 border-b border-line pb-4" @submit.prevent="addMember">
              <input v-model="memberUserId" type="number" required placeholder="User ID" class="min-w-28 flex-1 rounded-md border border-line px-2 py-1 text-xs" />
              <select v-model="memberRole" class="rounded-md border border-line px-2 py-1 text-xs">
                <option value="member">member</option>
                <option value="moderator">moderator</option>
              </select>
              <button type="submit" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving">Add member</button>
            </form>

            <form class="space-y-2 border-b border-line pb-4" @submit.prevent="postMessage">
              <h3 class="font-medium">Post message</h3>
              <select v-model="messageForm.message_type" class="w-full rounded-md border border-line px-2 py-1 text-xs">
                <option v-for="t in messageTypes" :key="t" :value="t">{{ t }}</option>
              </select>
              <textarea v-model="messageForm.body" rows="3" placeholder="Message body" class="w-full rounded-md border border-line px-2 py-1 text-xs"></textarea>
              <input
                v-if="['image', 'document', 'voice_note'].includes(messageForm.message_type)"
                v-model="messageForm.attachment_name"
                placeholder="Attachment name"
                class="w-full rounded-md border border-line px-2 py-1 text-xs"
              />
              <input
                v-if="messageForm.message_type === 'poll'"
                v-model="messageForm.poll_options"
                placeholder="Poll options (comma-separated)"
                class="w-full rounded-md border border-line px-2 py-1 text-xs"
              />
              <button type="submit" class="rounded-md bg-brand px-3 py-1.5 text-xs font-semibold text-white" :disabled="store.saving">Send</button>
            </form>

            <form class="flex gap-2 border-b border-line pb-4" @submit.prevent="runSearch">
              <input v-model="searchQuery" placeholder="Search visible messages" class="flex-1 rounded-md border border-line px-2 py-1 text-xs" />
              <button type="submit" class="rounded-md border border-line px-2 py-1 text-xs">Search</button>
            </form>

            <div v-if="store.searchResults.length" class="rounded-md border border-line bg-canvas p-3 text-xs">
              <p class="mb-2 font-medium">Search results</p>
              <p v-for="row in store.searchResults" :key="'s' + row.id" class="text-muted">
                #{{ row.id }} · {{ row.preview || '(empty)' }}
              </p>
            </div>

            <div class="space-y-2">
              <h3 class="font-medium">Messages</h3>
              <article
                v-for="msg in store.messages"
                :key="msg.id"
                class="rounded-md border border-line p-3"
              >
                <p class="text-xs text-muted">
                  <span v-if="msg.is_pinned">Pinned · </span>
                  {{ msg.message_type }} · {{ msg.status }} ·
                  {{ msg.sender?.name || ('user #' + msg.sender_user_id) }} ·
                  {{ msg.created_at }}
                </p>
                <p class="mt-1 whitespace-pre-wrap">{{ msg.body || msg.preview || '(removed)' }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                  <button type="button" class="rounded-md border border-line px-2 py-0.5 text-xs" :disabled="store.saving" @click="pin(msg.id)">Pin</button>
                  <button type="button" class="rounded-md border border-line px-2 py-0.5 text-xs" :disabled="store.saving" @click="remove(msg.id)">Remove</button>
                  <button type="button" class="rounded-md border border-line px-2 py-0.5 text-xs" :disabled="store.saving" @click="report(msg.id)">Report</button>
                </div>
              </article>
            </div>

            <form class="space-y-2 border-t border-line pt-4" @submit.prevent="saveIntegration">
              <h3 class="font-medium">Approved external integration</h3>
              <select v-model="integration.provider" class="w-full rounded-md border border-line px-2 py-1 text-xs">
                <option value="slack">slack</option>
                <option value="microsoft_teams">microsoft_teams</option>
              </select>
              <textarea v-model="integration.moderation_boundary" required rows="2" placeholder="Moderation boundary" class="w-full rounded-md border border-line px-2 py-1 text-xs"></textarea>
              <label class="flex items-center gap-2 text-xs">
                <input v-model="integration.consent_documented" type="checkbox" class="rounded border-line" />
                Consent documented
              </label>
              <button type="submit" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving">Save integration</button>
              <p v-if="store.selected.integrations?.length" class="text-xs text-muted">
                Configured: {{ store.selected.integrations.map((i) => i.provider + (i.enabled ? ' (on)' : '')).join(', ') }}
              </p>
            </form>
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
import { useCommunitySpacesStore } from '../stores/communitySpaces'

const store = useCommunitySpacesStore()
const drawerOpen = ref(false)
const memberUserId = ref('')
const memberRole = ref('member')
const searchQuery = ref('')

const spaceTypes = ['church', 'branch', 'ministry', 'team', 'cell', 'department', 'event']
const messageTypes = ['text', 'image', 'document', 'voice_note', 'poll', 'announcement']

const form = reactive({
  name: '',
  space_type: 'cell',
  branch_id: '',
  description: '',
})

const messageForm = reactive({
  message_type: 'text',
  body: '',
  attachment_name: '',
  poll_options: 'Yes,No',
})

const integration = reactive({
  provider: 'slack',
  consent_documented: true,
  moderation_boundary: 'ShepardOne remains the system of record for moderation.',
})

async function createSpace() {
  await store.create({
    name: form.name,
    space_type: form.space_type,
    branch_id: form.branch_id ? Number(form.branch_id) : null,
    description: form.description || null,
  })
}

async function openSpace(id) {
  await store.select(id)
}

async function addMember() {
  if (!store.selected) return
  await store.addMember(store.selected.id, {
    user_id: Number(memberUserId.value),
    role: memberRole.value,
  })
  memberUserId.value = ''
}

async function postMessage() {
  if (!store.selected) return
  const payload = {
    message_type: messageForm.message_type,
    body: messageForm.body,
  }
  if (['image', 'document', 'voice_note'].includes(messageForm.message_type) && messageForm.attachment_name) {
    const mime =
      messageForm.message_type === 'image'
        ? 'image/jpeg'
        : messageForm.message_type === 'voice_note'
          ? 'audio/mpeg'
          : 'application/pdf'
    payload.attachments = [
      {
        name: messageForm.attachment_name,
        mime,
        size_bytes: 1024,
        storage_key: 'ui/' + messageForm.attachment_name,
      },
    ]
  }
  if (messageForm.message_type === 'poll') {
    payload.poll_options = messageForm.poll_options
      .split(',')
      .map((o) => o.trim())
      .filter(Boolean)
  }
  await store.postMessage(store.selected.id, payload)
  messageForm.body = ''
}

async function runSearch() {
  if (!store.selected || !searchQuery.value.trim()) return
  await store.search(store.selected.id, searchQuery.value.trim())
}

async function pin(messageId) {
  if (!store.selected) return
  await store.pin(store.selected.id, messageId)
}

async function remove(messageId) {
  if (!store.selected) return
  await store.remove(store.selected.id, messageId, 'Removed via UI')
}

async function report(messageId) {
  if (!store.selected) return
  await store.report(store.selected.id, messageId, 'Reported via UI')
}

async function saveIntegration() {
  if (!store.selected) return
  await store.configureIntegration(store.selected.id, {
    provider: integration.provider,
    enabled: true,
    consent_documented: integration.consent_documented,
    identity_mapping: { strategy: 'membership_id' },
    moderation_boundary: integration.moderation_boundary,
    config: { channel: 'general' },
  })
}

onMounted(() => {
  store.fetchSpaces()
})
</script>
