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
            <p class="truncate text-sm font-semibold text-ink">Newsletters</p>
            <p class="truncate text-xs text-muted">Compose, approve, schedule, and measure visual digests</p>
          </div>
          <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="store.saving" @click="runDue">
            Process due
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Engage</p>
          <h1 class="font-serif text-3xl font-bold">Newsletters</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>
        <p v-if="store.processResult" class="mb-4 rounded-md border border-line bg-white px-4 py-3 text-sm text-muted">
          Last run: {{ JSON.stringify(store.processResult) }}
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createNewsletter">
            <h2 class="font-semibold">Compose draft</h2>
            <input v-model="form.name" required placeholder="Name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.subject" required placeholder="Subject" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.preview_text" placeholder="Preview text" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.branch_id" type="number" placeholder="Branch ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.audience_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="branch">Branch audience</option>
              <option value="members">Specific members</option>
            </select>
            <input
              v-if="form.audience_type === 'members'"
              v-model="form.member_ids"
              placeholder="Member IDs (comma-separated)"
              class="w-full rounded-md border border-line px-3 py-2 text-sm"
            />
            <textarea
              v-model="form.text_body"
              required
              rows="3"
              placeholder="Lead text section"
              class="w-full rounded-md border border-line px-3 py-2 text-sm"
            ></textarea>
            <input v-model="form.button_label" placeholder="Button label (optional)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.button_href" placeholder="Button URL (optional)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.unsubscribe_label" required placeholder="Unsubscribe label" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving">
              Create draft
            </button>
          </form>

          <section class="space-y-6">
            <div class="rounded-md border border-line bg-white p-5">
              <h2 class="mb-3 font-semibold">Newsletters</h2>
              <ul class="space-y-2 text-sm">
                <li v-for="item in store.items" :key="item.id" class="rounded-md border border-line p-3">
                  <button type="button" class="w-full text-left" @click="openItem(item.id)">
                    <p class="font-medium">{{ item.reference }} · {{ item.name }}</p>
                    <p class="text-xs text-muted">
                      {{ item.status }} · draft v{{ item.draft_version }} · approved v{{ item.approved_version || '—' }}
                    </p>
                  </button>
                </li>
              </ul>
            </div>

            <section v-if="store.selected" class="space-y-3 rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="font-semibold">{{ store.selected.reference }}</h2>
              <p class="text-xs text-muted">
                {{ store.selected.status }} · {{ store.selected.subject }}
              </p>
              <p class="text-xs text-muted">Sections: {{ (store.selected.sections || []).map((s) => s.type).join(', ') }}</p>

              <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="validate">Validate</button>
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="preview">Preview</button>
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="submit">Submit</button>
                <button type="button" class="rounded-md bg-brand px-2 py-1 text-xs font-semibold text-white" :disabled="store.saving" @click="approveNow">Approve</button>
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="approveScheduled">Approve + schedule</button>
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="loadAnalytics">Analytics</button>
              </div>

              <div class="flex flex-wrap items-end gap-2 border-t border-line pt-3">
                <input v-model="testMemberIds" placeholder="Test member IDs" class="min-w-40 flex-1 rounded-md border border-line px-2 py-1 text-xs" />
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="sendTest">Test send</button>
              </div>

              <div v-if="store.validation" class="rounded-md border border-line bg-canvas p-3 text-xs">
                <p class="font-medium text-ink">Validation: {{ store.validation.validation?.valid ? 'valid' : 'invalid' }}</p>
                <pre class="mt-1 overflow-auto whitespace-pre-wrap">{{ JSON.stringify(store.validation.validation, null, 2) }}</pre>
              </div>

              <div v-if="store.preview" class="rounded-md border border-line bg-canvas p-3 text-xs">
                <p class="font-medium text-ink">Preview · passed {{ store.preview.passed }}</p>
                <p v-for="row in store.preview.previews || []" :key="row.preview_id" class="mt-1 text-muted">
                  {{ row.viewport }} ({{ row.width }}px): {{ row.html_excerpt }}
                </p>
              </div>

              <div v-if="store.analytics" class="rounded-md border border-line bg-canvas p-3 text-xs">
                <p class="font-medium text-ink">Analytics</p>
                <p class="mt-1">{{ JSON.stringify(store.analytics.totals) }}</p>
                <p class="mt-2 text-muted">{{ store.analytics.privacy_note }}</p>
                <pre class="mt-2 overflow-auto whitespace-pre-wrap text-muted">{{ JSON.stringify(store.analytics.provider_limitations, null, 2) }}</pre>
              </div>

              <div v-if="store.selected.versions?.length" class="space-y-1 border-t border-line pt-3 text-xs text-muted">
                <h3 class="mb-1 text-sm font-medium text-ink">Versions</h3>
                <p v-for="row in store.selected.versions" :key="row.id">
                  v{{ row.version }} · {{ row.status }}
                  <span v-if="row.approved_at"> · approved {{ row.approved_at }}</span>
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
import { useNewslettersStore } from '../stores/newsletters'

const store = useNewslettersStore()
const drawerOpen = ref(false)
const testMemberIds = ref('')

const form = reactive({
  name: '',
  subject: '',
  preview_text: '',
  branch_id: '',
  audience_type: 'branch',
  member_ids: '',
  text_body: '',
  button_label: 'Learn more',
  button_href: 'https://example.com',
  unsubscribe_label: 'Unsubscribe',
})

function buildSections() {
  const sections = [{ type: 'text', body: form.text_body }]
  if (form.button_label && form.button_href) {
    sections.push({ type: 'button', label: form.button_label, href: form.button_href })
  }
  sections.push({ type: 'unsubscribe', label: form.unsubscribe_label })
  return sections
}

function audienceParams() {
  if (form.audience_type === 'members') {
    return {
      member_ids: form.member_ids
        .split(',')
        .map((id) => Number(id.trim()))
        .filter((id) => id > 0),
    }
  }
  return { branch_id: Number(form.branch_id) || null }
}

async function createNewsletter() {
  await store.create({
    name: form.name,
    subject: form.subject,
    preview_text: form.preview_text || null,
    branch_id: form.branch_id ? Number(form.branch_id) : null,
    audience_type: form.audience_type,
    audience_params: audienceParams(),
    sections: buildSections(),
  })
}

async function openItem(id) {
  await store.select(id)
}

async function validate() {
  if (!store.selected) return
  await store.validate(store.selected.id)
}

async function preview() {
  if (!store.selected) return
  await store.preview(store.selected.id)
}

async function submit() {
  if (!store.selected) return
  await store.submit(store.selected.id)
}

async function approveNow() {
  if (!store.selected) return
  await store.approve(store.selected.id)
}

async function approveScheduled() {
  if (!store.selected) return
  const when = new Date(Date.now() + 60_000).toISOString()
  await store.approve(store.selected.id, { scheduled_at: when })
}

async function sendTest() {
  if (!store.selected) return
  const member_ids = testMemberIds.value
    .split(',')
    .map((id) => Number(id.trim()))
    .filter((id) => id > 0)
  await store.sendTest(store.selected.id, { member_ids })
}

async function loadAnalytics() {
  if (!store.selected) return
  await store.fetchAnalytics(store.selected.id)
}

async function runDue() {
  await store.processDue()
}

onMounted(() => {
  store.fetchItems()
})
</script>
