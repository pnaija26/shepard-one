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
            <p class="truncate text-sm font-semibold text-ink">Global search</p>
            <p class="truncate text-xs text-muted">Find permitted church records in one place</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Records</p>
          <h1 class="font-serif text-3xl font-bold">Search church records</h1>
        </section>

        <form class="mb-6 flex gap-3" @submit.prevent="runSearch">
          <input v-model="query" required placeholder="Search members, events, documents..." class="flex-1 rounded-md border border-line px-3 py-2 text-sm" />
          <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.loading">Search</button>
        </form>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>

        <p v-if="store.results" class="mb-4 text-xs text-muted">
          {{ store.results.total_results }} results in {{ store.results.duration_ms }}ms
          <span v-if="store.results.within_target">· within target</span>
        </p>

        <section v-for="group in store.results?.groups || []" :key="group.record_type" class="mb-6 rounded-md border border-line bg-white p-5">
          <h2 class="mb-3 font-semibold">{{ group.label }} ({{ group.count }})</h2>
          <ul class="space-y-2 text-sm">
            <li v-for="item in group.items" :key="`${item.record_type}-${item.record_id}`" class="rounded-md border border-line px-3 py-2">
              <button type="button" class="text-left" @click="openResult(item)">
                <p class="font-medium">{{ item.title }}</p>
                <p class="text-xs text-muted">{{ item.snippet }}</p>
              </button>
            </li>
          </ul>
        </section>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useGlobalSearchStore } from '../stores/globalSearch'

const store = useGlobalSearchStore()
const router = useRouter()
const drawerOpen = ref(false)
const query = ref('')

async function runSearch() {
  await store.search(query.value)
}

async function openResult(item) {
  const resolved = await store.resolveRecord(item.record_type, item.record_id)
  if (resolved?.route) {
    await router.push(resolved.route)
  }
}

onMounted(async () => {
  await store.loadCatalog()
})
</script>
