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
            <p class="truncate text-sm font-semibold text-ink">Message templates</p>
            <p class="truncate text-xs text-muted">Versioned email and SMS content with safe variables</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Engage</p>
          <h1 class="font-serif text-3xl font-bold">Templates</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createTemplate">
            <h2 class="font-semibold">Create template</h2>
            <input v-model="form.name" required placeholder="Name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.scenario" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="s in scenarios" :key="s" :value="s">{{ s }}</option>
            </select>
            <select v-model="form.channel" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="email">Email</option>
              <option value="sms">SMS</option>
            </select>
            <select v-model="form.language" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="lang in languages" :key="lang" :value="lang">{{ lang }}</option>
            </select>
            <input v-model="form.branch_id" type="number" placeholder="Branch ID (optional)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-if="form.channel === 'email'" v-model="form.subject" required :placeholder="'Subject (supports {{first_name}})'" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <textarea v-model="form.body" required rows="6" :placeholder="'Body with {{variables}}'" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving">Create draft</button>
          </form>

          <section class="space-y-6">
            <div class="rounded-md border border-line bg-white p-5">
              <h2 class="mb-3 font-semibold">Templates</h2>
              <ul class="space-y-2 text-sm">
                <li v-for="item in store.templates" :key="item.id" class="rounded-md border border-line p-3">
                  <button type="button" class="w-full text-left" @click="openTemplate(item.id)">
                    <p class="font-medium">{{ item.name }}</p>
                    <p class="text-xs text-muted">
                      {{ item.status }} · {{ item.channel }} · {{ item.scenario }} · v{{ item.current_version || 0 }}
                    </p>
                  </button>
                </li>
              </ul>
            </div>

            <section v-if="store.selected" class="space-y-3 rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="font-semibold">{{ store.selected.name }}</h2>
              <p class="text-xs text-muted">
                {{ store.selected.status }} · draft v{{ store.selected.draft_version }} · published v{{ store.selected.current_version || 0 }}
              </p>
              <p class="whitespace-pre-wrap text-xs text-muted">{{ store.selected.subject }}</p>
              <p class="whitespace-pre-wrap">{{ store.selected.body }}</p>

              <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="validate">Validate</button>
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="preview">Preview</button>
                <button type="button" class="rounded-md bg-brand px-2 py-1 text-xs font-semibold text-white" :disabled="store.saving || store.selected.status === 'retired'" @click="publish">Publish</button>
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving || store.selected.status === 'retired'" @click="retire">Retire</button>
              </div>

              <div v-if="store.validation" class="rounded-md border border-line bg-canvas p-3 text-xs">
                <p class="font-medium text-ink">Validation: {{ store.validation.validation?.valid ? 'valid' : 'invalid' }}</p>
                <pre class="mt-1 overflow-auto whitespace-pre-wrap">{{ JSON.stringify(store.validation.validation, null, 2) }}</pre>
              </div>

              <div v-if="store.preview" class="rounded-md border border-line bg-canvas p-3 text-xs">
                <p class="font-medium text-ink">Preview</p>
                <p class="mt-2 font-semibold">{{ store.preview.rendered?.subject }}</p>
                <p class="mt-1 whitespace-pre-wrap">{{ store.preview.rendered?.body }}</p>
              </div>

              <div v-if="store.selected.versions?.length" class="space-y-1 border-t border-line pt-3 text-xs text-muted">
                <h3 class="mb-1 text-sm font-medium text-ink">Versions</h3>
                <p v-for="row in store.selected.versions" :key="row.id">
                  v{{ row.version }} · {{ row.status }}
                  <span v-if="row.effective_from"> · from {{ row.effective_from }}</span>
                  <span v-if="row.effective_to"> · to {{ row.effective_to }}</span>
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
import { useMessageTemplatesStore } from '../stores/messageTemplates'

const store = useMessageTemplatesStore()
const drawerOpen = ref(false)
const scenarios = ['birthday', 'anniversary', 'welcome', 'announcement', 'reminder', 'pastoral', 'welfare', 'custom']
const languages = ['en', 'fr', 'es', 'pt', 'sw']

const form = reactive({
  name: '',
  scenario: 'birthday',
  channel: 'email',
  language: 'en',
  branch_id: '',
  subject: 'Happy birthday {{first_name}}!',
  body: 'Dear {{preferred_name}}, blessings from {{branch_name}} on {{date}}.',
})

async function createTemplate() {
  await store.createTemplate({
    name: form.name,
    scenario: form.scenario,
    channel: form.channel,
    language: form.language,
    branch_id: form.branch_id ? Number(form.branch_id) : null,
    subject: form.channel === 'email' ? form.subject : null,
    body: form.body,
  })
}

async function openTemplate(id) {
  await store.selectTemplate(id)
}

async function validate() {
  if (!store.selected) return
  await store.validate(store.selected.id)
}

async function preview() {
  if (!store.selected) return
  await store.preview(store.selected.id)
}

async function publish() {
  if (!store.selected) return
  await store.publish(store.selected.id)
}

async function retire() {
  if (!store.selected) return
  await store.retire(store.selected.id)
}

onMounted(async () => {
  await store.fetchTemplates()
})
</script>
