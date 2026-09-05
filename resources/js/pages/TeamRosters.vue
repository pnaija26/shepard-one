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
            <p class="truncate text-sm font-semibold text-ink">Team rosters</p>
            <p class="truncate text-xs text-muted">Build, validate, and publish duty rosters</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Volunteers</p>
          <h1 class="font-serif text-3xl font-bold">Team rosters</h1>
        </section>

        <p v-if="rostersStore.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ rostersStore.error }}</p>

        <div class="mb-4 flex gap-2">
          <input v-model="teamId" type="number" placeholder="Service team ID" class="rounded-md border border-line px-3 py-2 text-sm" />
          <button type="button" class="rounded-md border border-line px-3 py-2 text-sm" @click="loadRosters">Load rosters</button>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Rosters</h2>
            <ul class="space-y-3 text-sm">
              <li v-for="roster in rostersStore.rosters" :key="roster.id" class="rounded-md border border-line p-3">
                <button type="button" class="w-full text-left" @click="rostersStore.selectRoster(roster.id)">
                  <p class="font-medium">{{ roster.title }} · {{ roster.status }}</p>
                  <p class="text-xs text-muted">{{ roster.roster_type }} · {{ roster.period_start }} to {{ roster.period_end }}</p>
                </button>
              </li>
            </ul>
          </section>

          <div class="space-y-6">
            <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createRoster">
              <h2 class="font-semibold">Create roster</h2>
              <input v-model="createForm.title" required placeholder="Roster title" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <select v-model="createForm.roster_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
                <option value="event">Event</option>
                <option value="shift">Shift</option>
              </select>
              <input v-model="createForm.period_start" type="date" required class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="createForm.period_end" type="date" required class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="rostersStore.saving || !teamId">
                Create roster
              </button>
            </form>

            <section v-if="rostersStore.selectedRoster" class="space-y-3 rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="font-semibold">{{ rostersStore.selectedRoster.title }}</h2>
              <div class="flex gap-2">
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="validateSelected">Validate</button>
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="publishSelected">Publish</button>
              </div>
              <pre v-if="rostersStore.validation" class="overflow-auto rounded-md bg-canvas p-2 text-xs">{{ rostersStore.validation }}</pre>
              <ul class="space-y-2">
                <li v-for="slot in rostersStore.selectedRoster.slots" :key="slot.id" class="rounded-md border border-line p-2">
                  {{ slot.member?.full_name }} · {{ slot.duty_label }} · {{ slot.status }}
                  <span v-if="slot.conflict_flags?.length" class="text-red-600">({{ slot.conflict_flags.join(', ') }})</span>
                </li>
              </ul>
              <form class="space-y-2 border-t border-line pt-3" @submit.prevent="addSlot">
                <input v-model="slotForm.member_id" type="number" required placeholder="Member ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <input v-model="slotForm.duty_label" required placeholder="Duty label" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <input v-model="slotForm.shift_date" type="date" required class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white">Add slot</button>
              </form>
            </section>
          </div>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useTeamRostersStore } from '../stores/teamRosters'
import { useServiceTeamsStore } from '../stores/serviceTeams'

const drawerOpen = ref(false)
const rostersStore = useTeamRostersStore()
const teamsStore = useServiceTeamsStore()
const teamId = ref('')

const createForm = reactive({
  title: '',
  roster_type: 'weekly',
  period_start: new Date().toISOString().slice(0, 10),
  period_end: new Date(Date.now() + 6 * 86400000).toISOString().slice(0, 10),
})

const slotForm = reactive({
  member_id: '',
  duty_label: 'Sunday service',
  shift_label: 'Morning',
  shift_date: new Date(Date.now() + 86400000).toISOString().slice(0, 10),
})

const loadRosters = async () => {
  if (!teamId.value) return
  await rostersStore.fetchRosters(Number(teamId.value))
}

const createRoster = async () => {
  if (!teamId.value) return
  await rostersStore.createRoster(Number(teamId.value), {
    ...createForm,
    staffing_requirements: {
      duties: [{ duty_label: 'Sunday service', required_count: 2 }],
    },
  })
}

const addSlot = async () => {
  if (!rostersStore.selectedRoster?.id) return
  await rostersStore.addSlot(rostersStore.selectedRoster.id, {
    member_id: Number(slotForm.member_id),
    duty_label: slotForm.duty_label,
    shift_label: slotForm.shift_label,
    shift_date: slotForm.shift_date,
  })
}

const validateSelected = async () => {
  if (!rostersStore.selectedRoster?.id) return
  await rostersStore.validateRoster(rostersStore.selectedRoster.id)
}

const publishSelected = async () => {
  if (!rostersStore.selectedRoster?.id) return
  await rostersStore.publishRoster(rostersStore.selectedRoster.id)
}

onMounted(() => {
  teamsStore.fetchTeams()
})
</script>
