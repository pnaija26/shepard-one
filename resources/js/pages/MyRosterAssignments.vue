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
            <p class="truncate text-sm font-semibold text-ink">My roster assignments</p>
            <p class="truncate text-xs text-muted">Accept, decline, or request replacement</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Volunteers</p>
          <h1 class="font-serif text-3xl font-bold">My roster assignments</h1>
        </section>

        <ul class="space-y-3 text-sm">
          <li v-for="slot in rostersStore.mySlots" :key="slot.id" class="rounded-md border border-line bg-white p-4">
            <p class="font-medium">{{ slot.roster?.title ?? 'Roster' }} · {{ slot.duty_label }}</p>
            <p class="text-xs text-muted">{{ slot.shift_date }} · {{ slot.status }}</p>
            <div v-if="slot.status === 'published'" class="mt-2 flex gap-2">
              <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="respond(slot.id, 'accepted')">Accept</button>
              <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="respond(slot.id, 'rejected', 'Unable to serve')">Decline</button>
              <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="respond(slot.id, 'replacement_requested', 'Need replacement')">Request replacement</button>
            </div>
          </li>
        </ul>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useTeamRostersStore } from '../stores/teamRosters'

const drawerOpen = ref(false)
const rostersStore = useTeamRostersStore()

const respond = async (slotId, response, reason = null) => {
  await rostersStore.respondToSlot(slotId, { response, reason })
}

onMounted(() => {
  rostersStore.fetchMySlots()
})
</script>
