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
            <p class="truncate text-sm font-semibold text-ink">Church documents</p>
            <p class="truncate text-xs text-muted">Upload and categorize protected evidence and records</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Records</p>
          <h1 class="font-serif text-3xl font-bold">Protected document library</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="uploadDocument">
            <h2 class="font-semibold">Upload document</h2>
            <input v-model="form.title" required placeholder="Title" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.category" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="category in store.catalog?.categories || []" :key="category" :value="category">{{ category }}</option>
            </select>
            <select v-model="form.record_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="(meta, type) in store.catalog?.record_types || {}" :key="type" :value="type">{{ meta.label }}</option>
            </select>
            <input v-model.number="form.record_id" type="number" placeholder="Linked record ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.classification" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="classification in store.catalog?.classifications || []" :key="classification" :value="classification">{{ classification }}</option>
            </select>
            <select v-model="form.access_scope" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="scope in store.catalog?.access_scopes || []" :key="scope" :value="scope">{{ scope }}</option>
            </select>
            <select v-model="form.retention_policy" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="(meta, policy) in store.catalog?.retention_policies || {}" :key="policy" :value="policy">{{ meta.label }}</option>
            </select>
            <input type="file" class="w-full rounded-md border border-line px-3 py-2 text-sm" @change="onFileSelected" />
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving || !filePayload">Upload</button>
          </form>

          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Recent documents</h2>
            <ul class="space-y-2 text-sm">
              <li v-for="document in store.documents" :key="document.id" class="rounded-md border border-line px-3 py-2">
                <button type="button" class="text-left" @click="selectDocument(document.id)">
                  <p class="font-medium">{{ document.title }}</p>
                  <p class="text-xs text-muted">v{{ document.version_number }} · {{ document.record_type }} #{{ document.record_id }} · {{ document.classification }}</p>
                </button>
              </li>
            </ul>
          </section>
        </div>

        <section v-if="store.selected" class="mt-6 rounded-md border border-line bg-white p-5">
          <h2 class="mb-3 font-semibold">Selected document</h2>
          <p class="text-sm">{{ store.selected.title }} · v{{ store.selected.version_number }}</p>
          <ul v-if="store.selected.versions?.length" class="mt-3 space-y-2 text-sm">
            <li v-for="version in store.selected.versions" :key="version.id" class="rounded-md border border-line px-3 py-2">
              <p class="font-medium">Version {{ version.version_number }} <span v-if="version.is_current" class="text-xs text-brand">(current)</span></p>
              <p class="text-xs text-muted">{{ version.original_filename }} · {{ version.content_hash.slice(0, 12) }}…</p>
              <p v-if="version.replacement_reason" class="text-xs text-muted">Reason: {{ version.replacement_reason }}</p>
            </li>
          </ul>
          <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" class="rounded-md border border-line px-3 py-2 text-sm" @click="downloadCurrent('preview')">Preview current</button>
            <button type="button" class="rounded-md border border-line px-3 py-2 text-sm" @click="downloadCurrent('download')">Download current</button>
          </div>
          <form v-if="replacementFile" class="mt-4 space-y-2 border-t border-line pt-4" @submit.prevent="replaceSelected">
            <h3 class="font-medium">Replace current version</h3>
            <input v-model="replacementReason" required placeholder="Reason for replacement" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input type="file" class="w-full rounded-md border border-line px-3 py-2 text-sm" @change="onReplacementSelected" />
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving">Upload replacement</button>
          </form>
        </section>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useChurchDocumentsStore } from '../stores/churchDocuments'
import * as churchDocumentsApi from '../api/churchDocuments'

const store = useChurchDocumentsStore()
const drawerOpen = ref(false)
const filePayload = ref(null)
const replacementFile = ref(null)
const replacementReason = ref('')

const form = reactive({
  title: '',
  category: 'evidence',
  record_type: 'member',
  record_id: '',
  classification: 'internal',
  access_scope: 'record_viewers',
  retention_policy: 'standard_7y',
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
    mime_type: file.type || 'application/octet-stream',
    size_bytes: file.size,
    content_base64: btoa(binary),
    content_hash: await sha256(bytes),
  }
}

async function sha256(bytes) {
  const digest = await crypto.subtle.digest('SHA-256', bytes)
  return Array.from(new Uint8Array(digest))
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('')
}

async function uploadDocument() {
  if (!filePayload.value) {
    return
  }

  await store.uploadDocument({
    ...form,
    ...filePayload.value,
    record_id: form.record_id || null,
  })
}

async function selectDocument(id) {
  await store.loadDocument(id)
}

async function onReplacementSelected(event) {
  const file = event.target.files?.[0]
  if (!file) {
    replacementFile.value = null
    return
  }

  const buffer = await file.arrayBuffer()
  const bytes = new Uint8Array(buffer)
  let binary = ''
  bytes.forEach((byte) => {
    binary += String.fromCharCode(byte)
  })

  replacementFile.value = {
    filename: file.name,
    mime_type: file.type || 'application/octet-stream',
    size_bytes: file.size,
    content_base64: btoa(binary),
    content_hash: await sha256(bytes),
  }
}

async function replaceSelected() {
  if (!store.selected || !replacementFile.value) {
    return
  }

  await store.replaceVersion(store.selected.id, {
    reason: replacementReason.value,
    ...replacementFile.value,
  })
  replacementReason.value = ''
  replacementFile.value = null
}

async function downloadCurrent(mode) {
  if (!store.selected) {
    return
  }

  const access = await store.issueAccess(store.selected.id, { mode })
  const response = await churchDocumentsApi.downloadChurchDocument(store.selected.id, {
    token: access.token,
    version_number: access.version_number,
  })
  const blobUrl = URL.createObjectURL(response.data)
  window.open(blobUrl, '_blank', 'noopener,noreferrer')
}

onMounted(async () => {
  await store.loadCatalog()
  await store.loadDocuments()
})
</script>
