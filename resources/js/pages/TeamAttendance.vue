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
            <p class="truncate text-sm font-semibold text-ink">Team attendance</p>
            <p class="truncate text-xs text-muted">Capture duty attendance and review reliability</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Volunteers</p>
          <h1 class="font-serif text-3xl font-bold">Team attendance</h1>
        </section>

        <p v-if="attendanceStore.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ attendanceStore.error }}</p>

        <div class="mb-4 flex gap-2">
          <input v-model="teamId" type="number" placeholder="Service team ID" class="rounded-md border border-line px-3 py-2 text-sm" />
          <button type="button" class="rounded-md border border-line px-3 py-2 text-sm" @click="loadTeam">Load team</button>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
          <section class="space-y-4 rounded-md border border-line bg-white p-5">
            <h2 class="font-semibold">Occurrences</h2>
            <form class="space-y-2" @submit.prevent="createOccurrence">
              <input v-model="occurrenceForm.title" required placeholder="Occurrence title" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <select v-model="occurrenceForm.occurrence_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option value="duty">Duty</option>
                <option value="rehearsal">Rehearsal</option>
                <option value="meeting">Meeting</option>
              </select>
              <input v-model="occurrenceForm.occurrence_date" type="date" required class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="!teamId">Create occurrence</button>
            </form>
            <ul class="space-y-2 text-sm">
              <li v-for="occurrence in attendanceStore.occurrences" :key="occurrence.id" class="rounded-md border border-line p-3">
                <button type="button" class="w-full text-left" @click="attendanceStore.selectOccurrence(occurrence.id)">
                  {{ occurrence.title }} · {{ occurrence.occurrence_date }} · {{ occurrence.status }}
                </button>
              </li>
            </ul>
            <form v-if="attendanceStore.selectedOccurrence" class="space-y-2 border-t border-line pt-3" @submit.prevent="captureAttendance">
              <h3 class="text-sm font-semibold">Capture attendance</h3>
              <input v-model="captureForm.member_id" type="number" required placeholder="Member ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <select v-model="captureForm.status" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option value="present">Present</option>
                <option value="absent">Absent</option>
                <option value="excused">Excused</option>
                <option value="late">Late</option>
              </select>
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white">Save attendance</button>
            </form>
          </section>

          <section class="rounded-md border border-line bg-white p-5 text-sm">
            <h2 class="mb-3 font-semibold">Reliability analysis</h2>
            <p v-if="attendanceStore.analysis" class="mb-2 text-muted">
              Team attendance only · gathering records in scope: {{ attendanceStore.analysis.gathering_attendance_records_in_scope }}
            </p>
            <p v-if="attendanceStore.analysis" class="mb-4">
              Overall: {{ attendanceStore.analysis.totals.attendance_percent }}% ·
              {{ attendanceStore.analysis.totals.records }} records
            </p>
            <ul v-if="attendanceStore.analysis?.members?.length" class="space-y-2">
              <li v-for="member in attendanceStore.analysis.members" :key="member.member_id" class="rounded-md border border-line p-2">
                {{ member.member_name }} · {{ member.attendance_percent }}% · {{ member.reliability }}
              </li>
            </ul>
            <div v-if="attendanceStore.analysis?.members_requiring_follow_up?.length" class="mt-4">
              <p class="font-medium">Requires follow-up</p>
              <ul class="mt-2 space-y-1">
                <li v-for="member in attendanceStore.analysis.members_requiring_follow_up" :key="member.member_id">
                  {{ member.member_name }} · {{ member.attendance_percent }}%
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
import { reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useTeamAttendanceStore } from '../stores/teamAttendance'

const drawerOpen = ref(false)
const attendanceStore = useTeamAttendanceStore()
const teamId = ref('')

const occurrenceForm = reactive({
  title: '',
  occurrence_type: 'rehearsal',
  occurrence_date: new Date().toISOString().slice(0, 10),
})

const captureForm = reactive({
  member_id: '',
  status: 'present',
})

const loadTeam = async () => {
  if (!teamId.value) return
  await attendanceStore.fetchOccurrences(Number(teamId.value))
  await attendanceStore.fetchAnalysis(Number(teamId.value))
}

const createOccurrence = async () => {
  if (!teamId.value) return
  await attendanceStore.createOccurrence(Number(teamId.value), occurrenceForm)
}

const captureAttendance = async () => {
  if (!attendanceStore.selectedOccurrence?.id) return
  await attendanceStore.captureAttendance(attendanceStore.selectedOccurrence.id, [{
    member_id: Number(captureForm.member_id),
    status: captureForm.status,
  }])
}
</script>
