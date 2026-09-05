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
            <p class="truncate text-sm font-semibold text-ink">Volunteer profiles</p>
            <p class="truncate text-xs text-muted">Capabilities, service history, and coordinator notes</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Volunteers</p>
          <h1 class="font-serif text-3xl font-bold">Volunteer profiles</h1>
        </section>

        <p v-if="volunteersStore.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ volunteersStore.error }}</p>

        <div class="mb-6 grid gap-4 md:grid-cols-2">
          <section class="rounded-md border border-line bg-white p-4 text-sm">
            <h2 class="mb-2 font-semibold">Expiring certifications</h2>
            <ul v-if="volunteersStore.alerts?.expiring_certifications?.length" class="space-y-2">
              <li v-for="item in volunteersStore.alerts.expiring_certifications" :key="`${item.profile_id}-${item.index}`">
                {{ item.member_name }} · {{ item.certification }} · {{ item.expires_at }}
              </li>
            </ul>
            <p v-else class="text-muted">No certifications expiring soon.</p>
          </section>
          <section class="rounded-md border border-line bg-white p-4 text-sm">
            <h2 class="mb-2 font-semibold">Unavailable now</h2>
            <ul v-if="volunteersStore.alerts?.unavailable_periods?.length" class="space-y-2">
              <li v-for="item in volunteersStore.alerts.unavailable_periods" :key="`${item.profile_id}-${item.index}`">
                {{ item.member_name }} · {{ item.from }} to {{ item.to }}
              </li>
            </ul>
            <p v-else class="text-muted">No active unavailable periods.</p>
          </section>
        </div>

        <div class="mb-6 rounded-md border border-line bg-white p-5">
          <h2 class="mb-3 font-semibold">Recommend volunteers for open duty</h2>
          <form class="grid gap-2 md:grid-cols-2" @submit.prevent="requestRecommendations">
            <input v-model="recommendForm.team_id" type="number" required placeholder="Service team ID" class="rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="recommendForm.duty_label" required placeholder="Duty label" class="rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="recommendForm.shift_date" type="date" required class="rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="recommendForm.required_skills" placeholder="Required skills (comma separated)" class="rounded-md border border-line px-3 py-2 text-sm" />
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white md:col-span-2" :disabled="volunteersStore.loading">Get recommendations</button>
          </form>
          <ul v-if="volunteersStore.recommendations?.length" class="mt-4 space-y-2 text-sm">
            <li v-for="item in volunteersStore.recommendations" :key="item.member_id" class="rounded-md border border-line p-3">
              <div class="flex items-center justify-between gap-2">
                <p class="font-medium">#{{ item.rank }} · {{ item.display_name }} · score {{ item.score }}</p>
                <span class="text-xs uppercase" :class="item.eligible ? 'text-green-700' : 'text-amber-700'">{{ item.eligible ? 'eligible' : 'limited' }}</span>
              </div>
              <p class="text-xs text-muted">{{ item.reasons?.join(' · ') }}</p>
              <p v-if="item.limitations?.length" class="text-xs text-amber-700">{{ item.limitations.join(' · ') }}</p>
              <button v-if="item.eligible" type="button" class="mt-2 rounded-md border border-line px-2 py-1 text-xs" @click="confirmRecommendation(item.member_id)">Confirm assignment</button>
            </li>
          </ul>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Profiles</h2>
            <ul class="space-y-3 text-sm">
              <li v-for="profile in volunteersStore.profiles" :key="profile.id" class="rounded-md border border-line p-3">
                <button type="button" class="w-full text-left" @click="volunteersStore.selectProfile(profile.id)">
                  <p class="font-medium">{{ profile.member?.full_name ?? 'Member' }}</p>
                  <p class="text-xs text-muted">{{ profile.skills?.join(', ') || 'No skills listed' }} · {{ profile.volunteer_hours }} hrs</p>
                </button>
              </li>
            </ul>
          </section>

          <div class="space-y-6">
            <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createProfile">
              <h2 class="font-semibold">Create profile</h2>
              <input v-model="createForm.member_id" type="number" required placeholder="Member ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="createForm.skills" placeholder="Skills (comma separated)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="createForm.volunteer_hours" type="number" min="0" step="0.5" placeholder="Volunteer hours" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <textarea v-model="createForm.restricted_notes" rows="3" placeholder="Restricted coordinator notes" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="volunteersStore.saving">
                Create profile
              </button>
            </form>

            <section v-if="volunteersStore.selectedProfile" class="space-y-3 rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="font-semibold">{{ volunteersStore.selectedProfile.member?.full_name }}</h2>
              <p><span class="font-medium">Skills:</span> {{ volunteersStore.selectedProfile.skills?.join(', ') || 'None' }}</p>
              <p><span class="font-medium">Hours:</span> {{ volunteersStore.selectedProfile.volunteer_hours }}</p>
              <p v-if="volunteersStore.selectedProfile.restricted_notes"><span class="font-medium">Restricted notes:</span> {{ volunteersStore.selectedProfile.restricted_notes }}</p>
              <div v-if="volunteersStore.selectedProfile.pending_changes?.length">
                <p class="font-medium">Pending verification</p>
                <div v-for="change in volunteersStore.selectedProfile.pending_changes" :key="change.id" class="mt-2 flex gap-2">
                  <span>{{ change.field }}</span>
                  <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="volunteersStore.verifyChange(change.id, true)">Approve</button>
                  <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="volunteersStore.verifyChange(change.id, false)">Reject</button>
                </div>
              </div>
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
import { useVolunteersStore } from '../stores/volunteers'

const drawerOpen = ref(false)
const volunteersStore = useVolunteersStore()

const createForm = reactive({
  member_id: '',
  skills: 'vocals, keyboard',
  volunteer_hours: 0,
  restricted_notes: '',
})

const recommendForm = reactive({
  team_id: '',
  duty_label: 'Sunday vocals',
  shift_date: '',
  required_skills: 'vocals',
})

const requestRecommendations = async () => {
  await volunteersStore.fetchRecommendations(Number(recommendForm.team_id), {
    duty_label: recommendForm.duty_label,
    shift_label: recommendForm.duty_label,
    shift_date: recommendForm.shift_date,
    shift_start: '09:00',
    shift_end: '11:00',
    day_of_week: 'sunday',
    required_skills: recommendForm.required_skills.split(',').map((skill) => skill.trim()).filter(Boolean),
    required_training: ['safeguarding'],
  })
}

const confirmRecommendation = async (memberId) => {
  await volunteersStore.confirmRecommendation(Number(recommendForm.team_id), {
    member_id: memberId,
    team_role: 'member',
    duty_label: recommendForm.duty_label,
    shift_label: recommendForm.duty_label,
    shift_date: recommendForm.shift_date,
    shift_start: '09:00',
    shift_end: '11:00',
    day_of_week: 'sunday',
    required_skills: recommendForm.required_skills.split(',').map((skill) => skill.trim()).filter(Boolean),
    required_training: ['safeguarding'],
  })
}

const createProfile = async () => {
  await volunteersStore.createProfile({
    member_id: Number(createForm.member_id),
    skills: createForm.skills.split(',').map((skill) => skill.trim()).filter(Boolean),
    volunteer_hours: Number(createForm.volunteer_hours),
    restricted_notes: createForm.restricted_notes || null,
    expertise: [],
    availability: { weekly: [], unavailable_periods: [] },
    preferences: {},
    experience: [],
    certifications: [],
    training: [],
    service_history: [],
  })
}

onMounted(() => {
  volunteersStore.fetchProfiles()
  volunteersStore.fetchAlerts()
})
</script>
