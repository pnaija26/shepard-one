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
            <p class="truncate text-sm font-semibold text-ink">Data migration</p>
            <p class="truncate text-xs text-muted">Profile, map, validate, and rehearse migration cutover</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Migration</p>
          <h1 class="font-serif text-3xl font-bold">Legacy data preparation</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="uploadSource">
            <h2 class="font-semibold">Upload source</h2>
            <input v-model="form.name" required placeholder="Source name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.source_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="type in store.catalog?.source_types || []" :key="type" :value="type">{{ type }}</option>
            </select>
            <input type="file" accept=".csv" class="w-full rounded-md border border-line px-3 py-2 text-sm" @change="onFileSelected" />
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving || !filePayload">Upload</button>
          </form>

          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Sources</h2>
            <ul class="space-y-2 text-sm">
              <li v-for="source in store.sources" :key="source.id" class="rounded-md border border-line px-3 py-2">
                <button type="button" class="text-left" @click="profileSource(source.id)">
                  <p class="font-medium">{{ source.name }}</p>
                  <p class="text-xs text-muted">{{ source.source_type }} · {{ source.status }} · {{ source.row_count }} rows</p>
                </button>
              </li>
            </ul>
          </section>
        </div>

        <section v-if="store.profile" class="mt-6 rounded-md border border-line bg-white p-5">
          <h2 class="mb-3 font-semibold">Profile summary</h2>
          <p class="text-sm">{{ store.profile.summary.row_count }} rows · {{ store.profile.summary.column_count }} columns</p>
          <p class="mt-2 text-xs text-muted">Sensitive fields: {{ store.profile.sensitive_fields?.length || 0 }} · Duplicate groups: {{ store.profile.duplicate_keys?.length || 0 }}</p>
        </section>

        <section v-if="store.validationRun" class="mt-6 rounded-md border border-line bg-white p-5">
          <h2 class="mb-3 font-semibold">Validation results</h2>
          <pre class="overflow-auto rounded-md bg-canvas p-3 text-xs">{{ store.validationRun.summary }}</pre>
        </section>

        <section v-if="store.cutoverPlan || store.cutoverRun" class="mt-6 rounded-md border border-line bg-white p-5">
          <h2 class="mb-3 font-semibold">Cutover rehearsal</h2>
          <p v-if="store.cutoverPlan" class="text-sm">Plan {{ store.cutoverPlan.reference }} · {{ store.cutoverPlan.status }}</p>
          <pre v-if="store.cutoverRun" class="mt-3 overflow-auto rounded-md bg-canvas p-3 text-xs">{{ store.cutoverRun }}</pre>
          <pre v-if="store.goLiveReport" class="mt-3 overflow-auto rounded-md bg-canvas p-3 text-xs">{{ store.goLiveReport }}</pre>
        </section>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useDataMigrationsStore } from '../stores/dataMigrations'

const store = useDataMigrationsStore()
const drawerOpen = ref(false)
const filePayload = ref(null)
const selectedSourceId = ref(null)

const form = reactive({
  name: '',
  source_type: 'csv',
})

async function onFileSelected(event) {
  const file = event.target.files?.[0]
  if (!file) {
    filePayload.value = null
    return
  }

  const buffer = await file.arrayBuffer()
  const bytes = new Uint8Array(buffer)
  let binary = ''
  bytes.forEach((byte) => {
    binary += String.fromCharCode(byte)
  })

  filePayload.value = {
    filename: file.name,
    content_base64: btoa(binary),
  }
}

async function uploadSource() {
  if (!filePayload.value) {
    return
  }

  const source = await store.uploadSource({
    ...form,
    ...filePayload.value,
  })
  selectedSourceId.value = source.id
}

async function profileSource(id) {
  selectedSourceId.value = id
  await store.profileSource(id)
}

onMounted(async () => {
  await store.loadCatalog()
  await store.loadSources()
})
</script>
