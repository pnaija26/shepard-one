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
            <p class="truncate text-sm font-semibold text-ink">Attendance capture</p>
            <p class="truncate text-xs text-muted">Record participation for services and sessions</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Operations</p>
          <h1 class="font-serif text-3xl font-bold">Capture attendance</h1>
        </section>

        <p v-if="error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

        <form class="max-w-xl space-y-4 rounded-md border border-line bg-white p-5" @submit.prevent="submit">
          <div>
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted">Session type</label>
            <select v-model="form.session_key" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="church_service">Church service</option>
              <option value="church_event">Church event</option>
              <option value="team">Team</option>
              <option value="group">Group</option>
            </select>
          </div>

          <input v-model="form.session_id" type="number" required placeholder="Session ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />

          <div class="grid gap-3 sm:grid-cols-2">
            <div>
              <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted">Capture method</label>
              <select v-model="form.capture_method" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option value="manual">Manual</option>
                <option value="qr">QR</option>
                <option value="mobile">Mobile</option>
                <option value="member_id">Member ID</option>
                <option value="barcode">Barcode</option>
                <option value="kiosk">Kiosk</option>
              </select>
            </div>
            <div>
              <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-muted">Status</label>
              <select v-model="form.status" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option value="present">Present</option>
                <option value="absent">Absent</option>
                <option value="excused">Excused</option>
                <option value="late">Late</option>
                <option value="online">Online</option>
                <option value="first_timer">First timer</option>
                <option value="visitor">Visitor</option>
              </select>
            </div>
          </div>

          <input v-if="form.capture_method === 'member_id'" v-model="form.membership_id" placeholder="Membership ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
          <textarea v-else-if="form.capture_method === 'qr'" v-model="form.token" rows="3" placeholder="Paste membership QR token" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
          <div v-else class="grid gap-3 sm:grid-cols-2">
            <input v-model="form.subject_id" type="number" required placeholder="Person ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.subject_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="App\\Models\\Member">Member</option>
              <option value="App\\Models\\Visitor">Visitor</option>
            </select>
          </div>

          <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="loading">
            Record attendance
          </button>
        </form>

        <p v-if="result" class="mt-4 rounded-md border border-line bg-white p-4 text-sm">
          Recorded {{ result.status }} for person #{{ result.subject_id }} ({{ result.capture_method }})
        </p>
      </main>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import attendanceApi from '../api/attendance'

const drawerOpen = ref(false)
const loading = ref(false)
const error = ref(null)
const result = ref(null)

const form = reactive({
  session_key: 'church_service',
  session_id: '',
  capture_method: 'manual',
  status: 'present',
  subject_type: 'App\\Models\\Member',
  subject_id: '',
  membership_id: '',
  token: '',
})

const submit = async () => {
  loading.value = true
  error.value = null
  result.value = null

  const payload = {
    session_key: form.session_key,
    session_id: Number(form.session_id),
    capture_method: form.capture_method,
    status: form.status,
  }

  if (form.capture_method === 'member_id') {
    payload.membership_id = form.membership_id
  } else if (form.capture_method === 'qr') {
    payload.token = form.token
  } else {
    payload.subject_type = form.subject_type
    payload.subject_id = Number(form.subject_id)
  }

  try {
    const response = await attendanceApi.captureAttendance(payload)
    result.value = response.data?.data
  } catch (err) {
    error.value = err.response?.data?.message ?? 'Unable to capture attendance'
  } finally {
    loading.value = false
  }
}
</script>
