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
            <p class="truncate text-sm font-semibold text-ink">Church services</p>
            <p class="truncate text-xs text-muted">Branch schedules, venues, and livestream plans</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Operations</p>
          <h1 class="font-serif text-3xl font-bold">Service schedule</h1>
        </section>

        <p v-if="serviceStore.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger">
          {{ serviceStore.error }}
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Scheduled services</h2>
            <ul class="space-y-3 text-sm">
              <li v-for="service in serviceStore.services" :key="service.id" class="rounded-md border border-line p-3">
                <p class="font-medium">{{ service.title || service.service_type }}</p>
                <p class="text-xs text-muted">{{ service.service_date }} · {{ service.start_time }} · {{ service.venue }}</p>
                <p class="mt-1 text-xs uppercase tracking-wide text-muted">{{ service.status }}</p>
              </li>
            </ul>
          </section>

          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Create service</h2>
            <form class="space-y-3" @submit.prevent="createService">
              <input v-model="form.title" required placeholder="Title" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="form.branch_id" type="number" placeholder="Branch ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="form.service_date" type="date" required class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <div class="grid grid-cols-2 gap-3">
                <input v-model="form.start_time" type="time" required class="rounded-md border border-line px-3 py-2 text-sm" />
                <input v-model="form.end_time" type="time" class="rounded-md border border-line px-3 py-2 text-sm" />
              </div>
              <input v-model="form.venue" required placeholder="Venue" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="form.capacity" type="number" placeholder="Capacity" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="form.attendance_target" type="number" placeholder="Attendance target" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="serviceStore.saving">
                Create & publish
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
import { useChurchServiceStore } from '../stores/churchServices'

const serviceStore = useChurchServiceStore()
const drawerOpen = ref(false)

const form = reactive({
  branch_id: '',
  service_type: 'sunday_service',
  title: '',
  service_date: '',
  start_time: '09:00',
  end_time: '11:30',
  venue: 'Main Auditorium',
  ministers: [{ name: 'Lead Pastor', role: 'lead' }],
  teams: [{ name: 'Worship Team' }],
  capacity: 500,
  registration_required: true,
  registration_capacity: 450,
  attendance_target: 400,
  livestream: { enabled: true, platform: 'youtube' },
})

const createService = async () => {
  await serviceStore.createAndPublish({ ...form, branch_id: form.branch_id || null })
  form.title = ''
}

onMounted(() => serviceStore.fetchServices())
</script>
