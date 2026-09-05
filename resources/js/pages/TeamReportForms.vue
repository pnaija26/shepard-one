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
            <p class="truncate text-sm font-semibold text-ink">Team report forms</p>
            <p class="truncate text-xs text-muted">Configure team-specific reporting fields</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Volunteers</p>
          <h1 class="font-serif text-3xl font-bold">Team report forms</h1>
        </section>

        <p v-if="formsStore.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ formsStore.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <section class="space-y-4 rounded-md border border-line bg-white p-5">
            <h2 class="font-semibold">Forms</h2>
            <ul class="space-y-2 text-sm">
              <li v-for="form in formsStore.forms" :key="form.id" class="rounded-md border border-line p-3">
                <button type="button" class="w-full text-left" @click="formsStore.selectForm(form.id)">
                  {{ form.name }} · {{ form.status }} · v{{ form.current_version || form.draft_version }}
                </button>
              </li>
            </ul>
          </section>

          <div class="space-y-6">
            <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="submitCreateForm">
              <h2 class="font-semibold">Create form</h2>
              <input v-model="createForm.name" required placeholder="Form name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <textarea v-model="createForm.fieldsJson" rows="8" required placeholder="Fields JSON" class="w-full rounded-md border border-line px-3 py-2 font-mono text-xs"></textarea>
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="formsStore.saving">Create draft</button>
            </form>

            <section v-if="formsStore.selectedForm" class="space-y-3 rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="font-semibold">{{ formsStore.selectedForm.name }}</h2>
              <p>Status: {{ formsStore.selectedForm.status }} · Draft v{{ formsStore.selectedForm.draft_version }}</p>
              <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="previewSelected">Preview</button>
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="publishSelected">Publish</button>
              </div>
              <pre v-if="formsStore.preview" class="overflow-auto rounded-md bg-canvas p-2 text-xs">{{ formsStore.preview }}</pre>
              <input v-model="publishTeamId" type="number" placeholder="Team ID to assign" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            </section>
          </div>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useTeamReportFormsStore } from '../stores/teamReportForms'

const drawerOpen = ref(false)
const formsStore = useTeamReportFormsStore()
const publishTeamId = ref('')

const defaultFields = [
  { key: 'attendance_count', label: 'Attendance count', type: 'number', required: true },
  { key: 'service_date', label: 'Service date', type: 'date', required: true },
  { key: 'coverage', label: 'Coverage', type: 'dropdown', required: true, options: ['excellent', 'good', 'needs_improvement'] },
  { key: 'summary', label: 'Summary', type: 'text', required: true },
]

const createForm = reactive({
  name: '',
  fieldsJson: JSON.stringify(defaultFields, null, 2),
})

async function submitCreateForm() {
  const fields = JSON.parse(createForm.fieldsJson)
  await formsStore.createForm({ name: createForm.name, fields })
}

async function previewSelected() {
  if (!formsStore.selectedForm) return
  await formsStore.previewForm(formsStore.selectedForm.id)
}

async function publishSelected() {
  if (!formsStore.selectedForm || !publishTeamId.value) return
  await formsStore.publishForm(formsStore.selectedForm.id, [Number(publishTeamId.value)])
  await formsStore.loadForms()
}

onMounted(() => {
  formsStore.loadForms()
})
</script>
