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
            <p class="truncate text-sm font-semibold text-ink">Directory privacy</p>
            <p class="truncate text-xs text-muted">Control what others can see about you</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Privacy</p>
          <h1 class="font-serif text-3xl font-bold">Church directory visibility</h1>
          <p class="mt-1 text-sm text-muted">Choose which eligible details appear in the privacy-filtered directory</p>
        </section>

        <p v-if="directoryStore.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger">
          {{ directoryStore.error }}
        </p>

        <div v-if="directoryStore.loading" class="text-sm text-muted">Loading settings…</div>

        <form v-else-if="directoryStore.settings" class="max-w-2xl space-y-4 rounded-md border border-line bg-white p-6" @submit.prevent="saveSettings">
          <label class="flex items-center gap-2 text-sm">
            <input v-model="form.consent_directory" type="checkbox" />
            Include me in the church directory
          </label>
          <p v-if="directoryStore.settings.directory_consent_at" class="text-xs text-muted">
            Directory consent recorded {{ formatDate(directoryStore.settings.directory_consent_at) }}
          </p>
          <p v-if="directoryStore.settings.pending_effective_at" class="text-xs text-brand">
            Pending changes take effect by {{ formatDate(directoryStore.settings.pending_effective_at) }}
          </p>

          <div class="space-y-3 border-t border-line pt-4">
            <div v-for="field in directoryStore.settings.fields" :key="field.field" class="grid gap-2 sm:grid-cols-2 sm:items-center">
              <div>
                <p class="text-sm font-medium">{{ field.label }}</p>
                <p v-if="!field.publishable" class="text-xs text-danger">Cannot be published</p>
                <p v-else-if="field.pending_visibility" class="text-xs text-brand">Pending: {{ field.pending_visibility }}</p>
              </div>
              <select
                v-model="form.visibility[field.field]"
                :disabled="!form.consent_directory || !field.publishable"
                class="rounded-md border border-line px-3 py-2 text-sm disabled:bg-canvas"
              >
                <option v-for="(label, key) in directoryStore.settings.visibility_levels" :key="key" :value="key">{{ label }}</option>
              </select>
            </div>
          </div>

          <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="directoryStore.saving">
            {{ directoryStore.saving ? 'Saving…' : 'Save directory settings' }}
          </button>
        </form>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useDirectoryStore } from '../stores/directory'

const directoryStore = useDirectoryStore()
const drawerOpen = ref(false)

const form = reactive({
  consent_directory: false,
  visibility: {},
})

const syncForm = () => {
  if (!directoryStore.settings) return
  form.consent_directory = directoryStore.settings.consent_directory
  form.visibility = {}
  for (const field of directoryStore.settings.fields) {
    form.visibility[field.field] = field.pending_visibility || field.visibility
  }
}

const saveSettings = async () => {
  await directoryStore.updateSettings({
    consent_directory: form.consent_directory,
    visibility: { ...form.visibility },
  })
  syncForm()
}

const formatDate = (value) => new Date(value).toLocaleString()

onMounted(async () => {
  await directoryStore.fetchSettings()
  syncForm()
})
</script>
