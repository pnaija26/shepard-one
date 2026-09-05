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
            <p class="truncate text-sm font-semibold text-ink">Church content</p>
            <p class="truncate text-xs text-muted">Draft, approve, and publish announcements, sermons, and more</p>
          </div>
          <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="store.saving" @click="runWindows">
            Process windows
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Engage</p>
          <h1 class="font-serif text-3xl font-bold">Content</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>
        <p v-if="store.processResult" class="mb-4 rounded-md border border-line bg-white px-4 py-3 text-sm text-muted">
          Last window run: {{ JSON.stringify(store.processResult) }}
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createContent">
            <h2 class="font-semibold">Create draft</h2>
            <select v-model="form.content_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="t in contentTypes" :key="t" :value="t">{{ t }}</option>
            </select>
            <input v-model="form.title" required placeholder="Title" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <textarea v-model="form.body" rows="4" placeholder="Body" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
            <input v-model="form.branch_id" type="number" placeholder="Branch ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.visibility" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="public">public</option>
              <option value="members">members</option>
              <option value="branch">branch</option>
              <option value="role">role</option>
            </select>
            <select v-model="form.device_target" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="all">all devices</option>
              <option value="web">web</option>
              <option value="mobile">mobile</option>
            </select>
            <input v-model="form.publish_from" type="datetime-local" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.publish_to" type="datetime-local" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.image_url" placeholder="Optional image URL" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.image_alt" placeholder="Image alt text (required if image)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.link_href" placeholder="Optional link URL" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving">Create draft</button>
          </form>

          <section class="space-y-6">
            <div class="rounded-md border border-line bg-white p-5">
              <h2 class="mb-3 font-semibold">Admin library</h2>
              <ul class="space-y-2 text-sm">
                <li v-for="item in store.items" :key="item.id" class="rounded-md border border-line p-3">
                  <button type="button" class="w-full text-left" @click="openItem(item.id)">
                    <p class="font-medium">{{ item.reference }} · {{ item.title }}</p>
                    <p class="text-xs text-muted">{{ item.status }} · {{ item.content_type }}</p>
                  </button>
                </li>
              </ul>
            </div>

            <section v-if="store.selected" class="space-y-3 rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="font-semibold">{{ store.selected.title }}</h2>
              <p class="text-xs text-muted">{{ store.selected.reference }} · {{ store.selected.status }} · {{ store.selected.content_type }}</p>
              <p class="whitespace-pre-wrap">{{ store.selected.body }}</p>

              <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="validate">Validate</button>
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="preview">Preview</button>
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="submit">Submit</button>
                <button type="button" class="rounded-md bg-brand px-2 py-1 text-xs font-semibold text-white" :disabled="store.saving" @click="approve">Approve</button>
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="withdraw">Withdraw</button>
              </div>

              <div v-if="store.validation" class="rounded-md border border-line bg-canvas p-3 text-xs">
                <p class="font-medium">Validation: {{ store.validation.validation?.valid ? 'valid' : 'invalid' }}</p>
                <pre class="mt-1 overflow-auto whitespace-pre-wrap">{{ JSON.stringify(store.validation.validation, null, 2) }}</pre>
              </div>

              <div v-if="store.preview" class="rounded-md border border-line bg-canvas p-3 text-xs">
                <p class="font-medium">Preview · passed {{ store.preview.passed }}</p>
                <p v-for="row in store.preview.previews || []" :key="row.preview_id" class="mt-1 text-muted">
                  {{ row.device }}: {{ row.body_excerpt }}
                </p>
              </div>
            </section>

            <div class="rounded-md border border-line bg-white p-5">
              <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="font-semibold">Published feed</h2>
                <form class="flex gap-2" @submit.prevent="runSearch">
                  <input v-model="searchQuery" placeholder="Search" class="rounded-md border border-line px-2 py-1 text-xs" />
                  <button type="submit" class="rounded-md border border-line px-2 py-1 text-xs">Go</button>
                </form>
              </div>
              <ul class="space-y-2 text-sm">
                <li v-for="item in (store.searchResults.length ? store.searchResults : store.feed)" :key="'f' + item.id" class="rounded-md border border-line p-3">
                  <p class="font-medium">{{ item.title }}</p>
                  <p class="text-xs text-muted">{{ item.content_type }} · {{ item.published_at || item.status }}</p>
                  <p class="mt-1 text-xs">{{ item.body }}</p>
                </li>
              </ul>
            </div>
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
import { useChurchContentStore } from '../stores/churchContent'

const store = useChurchContentStore()
const drawerOpen = ref(false)
const searchQuery = ref('')
const contentTypes = ['announcement', 'verse', 'news', 'sermon', 'article', 'testimony', 'media', 'download', 'event']

const form = reactive({
  content_type: 'announcement',
  title: '',
  body: '',
  branch_id: '',
  visibility: 'members',
  device_target: 'all',
  publish_from: '',
  publish_to: '',
  image_url: '',
  image_alt: '',
  link_href: '',
})

async function createContent() {
  const payload = {
    content_type: form.content_type,
    title: form.title,
    body: form.body,
    branch_id: form.branch_id ? Number(form.branch_id) : null,
    visibility: form.visibility,
    audience_type: 'all',
    device_target: form.device_target,
    publish_from: form.publish_from || null,
    publish_to: form.publish_to || null,
    media: [],
    links: [],
  }
  if (form.image_url) {
    payload.media.push({
      name: 'image',
      mime: 'image/jpeg',
      size_bytes: 1024,
      url: form.image_url,
      alt: form.image_alt,
    })
  }
  if (form.link_href) {
    payload.links.push({ label: 'Open', href: form.link_href })
  }
  await store.create(payload)
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
  await store.preview(store.selected.id, { devices: ['web', 'mobile'] })
}

async function submit() {
  if (!store.selected) return
  await store.submit(store.selected.id)
}

async function approve() {
  if (!store.selected) return
  await store.approve(store.selected.id, { publish_now: true })
}

async function withdraw() {
  if (!store.selected) return
  await store.withdraw(store.selected.id)
}

async function runWindows() {
  await store.processWindows()
}

async function runSearch() {
  if (!searchQuery.value.trim()) {
    store.searchResults = []
    return
  }
  await store.search(searchQuery.value.trim())
}

onMounted(async () => {
  await store.fetchItems()
  await store.fetchFeed({ device: 'web' })
})
</script>
