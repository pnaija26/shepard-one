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
            <p class="truncate text-sm font-semibold text-ink">Member directory</p>
            <p class="truncate text-xs text-muted">Privacy-filtered church directory</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Community</p>
          <h1 class="font-serif text-3xl font-bold">Church directory</h1>
          <p class="mt-1 text-sm text-muted">Only members and fields you are permitted to see under consent and role rules</p>
        </section>

        <div class="mb-4 flex max-w-xl gap-2">
          <input
            v-model="search"
            type="search"
            placeholder="Search by name"
            class="flex-1 rounded-md border border-line bg-white px-3 py-2 text-sm"
            @keyup.enter="runSearch"
          />
          <button type="button" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="directoryStore.loading" @click="runSearch">
            Search
          </button>
        </div>

        <p v-if="directoryStore.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger">
          {{ directoryStore.error }}
        </p>

        <div v-if="directoryStore.loading" class="text-sm text-muted">Searching directory…</div>

        <div v-else-if="directoryStore.results.length === 0" class="rounded-md border border-line bg-white p-8 text-center text-sm text-muted">
          No directory entries match your search.
        </div>

        <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <article v-for="entry in directoryStore.results" :key="entry.id" class="rounded-md border border-line bg-white p-4">
            <h2 class="font-semibold">{{ entry.full_name }}</h2>
            <dl class="mt-3 space-y-1 text-sm">
              <div v-for="(value, field) in entry.fields" :key="field" class="flex justify-between gap-3">
                <dt class="text-muted capitalize">{{ field.replace('_', ' ') }}</dt>
                <dd class="text-right font-medium">{{ displayValue(value) }}</dd>
              </div>
            </dl>
          </article>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useDirectoryStore } from '../stores/directory'

const directoryStore = useDirectoryStore()
const drawerOpen = ref(false)
const search = ref('')

const runSearch = async () => {
  await directoryStore.search({ search: search.value })
}

const displayValue = (value) => {
  if (value && typeof value === 'object') return value.name || JSON.stringify(value)
  return value
}

onMounted(async () => {
  await runSearch()
})
</script>
