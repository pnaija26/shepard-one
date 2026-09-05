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
            <p class="truncate text-sm font-semibold text-ink">Configuration</p>
            <p class="truncate text-xs text-muted">Governed platform settings</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Platform configuration</p>
          <h1 class="font-serif text-3xl font-bold">Settings</h1>
          <p class="mt-1 text-sm text-muted">Manage operational settings without developer intervention</p>
        </section>

        <p v-if="configStore.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger" role="alert">
          {{ configStore.error }}
        </p>

        <div v-if="!configStore.loading && configStore.categories.length > 0" class="mb-4 flex flex-wrap gap-2">
          <button
            type="button"
            :class="categoryClass('')"
            @click="configStore.selectedCategory = ''"
          >
            All
          </button>
          <button
            v-for="category in configStore.categories"
            :key="category.name"
            type="button"
            :class="categoryClass(category.name)"
            @click="configStore.selectedCategory = category.name"
          >
            {{ category.name }}
          </button>
        </div>

        <div v-if="configStore.loading" class="text-sm text-muted">Loading settings…</div>

        <div v-else-if="configStore.filteredSettings.length === 0" class="rounded-md border border-line bg-white p-8 text-center">
          <p class="font-medium text-ink">No settings to display</p>
          <p class="mt-1 text-sm text-muted">
            <template v-if="configStore.settings.length === 0">
              Platform settings have not been seeded yet. Run
              <code class="rounded bg-canvas px-1 py-0.5 text-xs">php artisan db:seed --class=ConfigurationSeeder</code>
              to load defaults.
            </template>
            <template v-else>
              No settings match the selected category. Try another category or choose All.
            </template>
          </p>
        </div>

        <div v-else class="space-y-4">
          <div v-for="setting in configStore.filteredSettings" :key="setting.key" class="border border-line bg-white p-4 shadow-sm">
            <div class="flex items-start justify-between gap-4">
              <div class="min-w-0 flex-1">
                <p class="font-medium">{{ setting.key }}</p>
                <p class="text-xs text-muted">{{ setting.description }}</p>
                <p v-if="setting.is_locked" class="mt-1 text-xs font-semibold text-danger">Centrally locked</p>
                <p v-if="setting.draft_value !== null" class="mt-1 text-xs text-brand">Draft pending publish</p>
              </div>
              <div class="flex gap-2">
                <button
                  type="button"
                  class="rounded-md border border-line px-3 py-1.5 text-xs font-semibold text-ink hover:bg-canvas disabled:opacity-50"
                  :disabled="setting.is_locked || configStore.saving"
                  @click="saveDraft(setting)"
                >
                  Save draft
                </button>
                <button
                  type="button"
                  class="rounded-md bg-brand px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-hover disabled:opacity-50"
                  :disabled="setting.is_locked || configStore.saving || setting.draft_value === null"
                  @click="publish(setting.key)"
                >
                  Publish
                </button>
              </div>
            </div>

            <div class="mt-3 grid gap-3 sm:grid-cols-2">
              <div>
                <label class="text-xs font-medium text-muted">Active value</label>
                <input
                  :value="displayValue(setting.value, setting.type)"
                  type="text"
                  readonly
                  class="mt-1 block w-full rounded-md border border-line bg-canvas/50 px-3 py-2 text-sm"
                />
              </div>
              <div>
                <label class="text-xs font-medium text-muted">Draft value</label>
                <input
                  v-model="drafts[setting.key]"
                  type="text"
                  :disabled="setting.is_locked"
                  class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 text-sm disabled:bg-canvas/50"
                />
              </div>
            </div>
          </div>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { reactive, onMounted, ref, watch } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useConfigurationStore } from '../stores/configuration'

const configStore = useConfigurationStore()
const drawerOpen = ref(false)
const drafts = reactive({})

const categoryClass = (name) => [
  'rounded-md px-3 py-1.5 text-sm font-medium',
  configStore.selectedCategory === name ? 'bg-brand text-white' : 'bg-canvas text-muted hover:text-ink',
]

const displayValue = (value, type) => {
  if (type === 'json') return JSON.stringify(value)
  if (type === 'boolean') return value ? 'true' : 'false'
  return value ?? ''
}

const syncDrafts = () => {
  configStore.filteredSettings.forEach((setting) => {
    drafts[setting.key] = displayValue(setting.draft_value ?? setting.value, setting.type)
  })
}

const saveDraft = async (setting) => {
  let value = drafts[setting.key]
  if (setting.type === 'integer') value = Number(value)
  if (setting.type === 'boolean') value = value === 'true' || value === true
  if (setting.type === 'json') value = JSON.parse(value)
  await configStore.stageSetting(setting.key, value)
  syncDrafts()
}

const publish = async (key) => {
  await configStore.publishSetting(key)
  syncDrafts()
}

watch(() => configStore.filteredSettings, syncDrafts, { deep: true })

onMounted(async () => {
  await configStore.fetchAll()
  syncDrafts()
})
</script>
