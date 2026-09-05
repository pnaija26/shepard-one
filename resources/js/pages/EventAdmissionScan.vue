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
            <p class="truncate text-sm font-semibold text-ink">Event admission</p>
            <p class="truncate text-xs text-muted">Scan QR credentials at the door</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Operations</p>
          <h1 class="font-serif text-3xl font-bold">Scan event credential</h1>
        </section>

        <form class="max-w-xl space-y-4 rounded-md border border-line bg-white p-5" @submit.prevent="scan">
          <input v-model="eventId" type="number" placeholder="Event ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
          <textarea v-model="token" required rows="4" placeholder="Paste QR credential token" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
          <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="loading">
            Verify admission
          </button>
        </form>

        <p v-if="result" class="mt-4 rounded-md border border-line bg-white p-4 text-sm">
          {{ result.admitted ? 'Admitted' : 'Not admitted' }} — {{ result.message }}
        </p>
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import eventsApi from '../api/events'

const drawerOpen = ref(false)
const eventId = ref('')
const token = ref('')
const loading = ref(false)
const result = ref(null)

const scan = async () => {
  loading.value = true
  result.value = null
  try {
    const response = await eventsApi.scanAdmission({
      token: token.value,
      event_id: eventId.value ? Number(eventId.value) : null,
    })
    result.value = response.data?.data
  } catch (error) {
    result.value = error.response?.data?.data ?? { admitted: false, message: error.response?.data?.message ?? 'Scan failed' }
  } finally {
    loading.value = false
  }
}
</script>
