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
            <p class="truncate text-sm font-semibold text-ink">Church events</p>
            <p class="truncate text-xs text-muted">Plan registrations, volunteers, and post-event reporting</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Operations</p>
          <h1 class="font-serif text-3xl font-bold">Events</h1>
        </section>

        <p v-if="eventStore.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger">
          {{ eventStore.error }}
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Events</h2>
            <ul class="space-y-3 text-sm">
              <li v-for="event in eventStore.events" :key="event.id" class="rounded-md border border-line p-3">
                <p class="font-medium">{{ event.title }}</p>
                <p class="text-xs text-muted">{{ event.event_date }} · {{ event.venue }} · {{ event.status }}</p>
                <p v-if="event.budget_restricted" class="mt-1 text-xs text-muted">Budget restricted</p>
              </li>
            </ul>
          </section>

          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Create draft event</h2>
            <form class="space-y-3" @submit.prevent="createEvent">
              <input v-model="form.title" required placeholder="Event title" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="form.branch_id" type="number" placeholder="Branch ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="form.event_date" type="date" required class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="form.venue" required placeholder="Venue" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="form.capacity" type="number" placeholder="Capacity" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="eventStore.saving">
                Save draft
              </button>
            </form>
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
import { useChurchEventStore } from '../stores/churchEvents'

const eventStore = useChurchEventStore()
const drawerOpen = ref(false)

const form = reactive({
  branch_id: '',
  title: '',
  event_date: '',
  start_time: '09:00',
  end_time: '17:00',
  venue: '',
  capacity: 200,
  speakers: [{ name: 'Main Speaker' }],
  registration: { enabled: true, capacity: 180 },
  ticketing_policy: { type: 'free' },
  volunteers: [{ role: 'registration', count: 4 }],
  materials: [{ item: 'Handouts', quantity: 200 }],
  budget: { estimated: 1000, currency: 'NGN' },
  reminders: [{ channel: 'email', days_before: 2 }],
})

const createEvent = async () => {
  await eventStore.createDraft({ ...form, branch_id: form.branch_id || null })
  form.title = ''
}

onMounted(() => eventStore.fetchEvents())
</script>
