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
            <p class="truncate text-sm font-semibold text-ink">My volunteer profile</p>
            <p class="truncate text-xs text-muted">Share skills, availability, and service preferences</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Volunteers</p>
          <h1 class="font-serif text-3xl font-bold">My volunteer profile</h1>
        </section>

        <p v-if="volunteersStore.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ volunteersStore.error }}</p>
        <p v-if="volunteersStore.message" class="mb-4 rounded-md border border-line bg-white px-4 py-3 text-sm">{{ volunteersStore.message }}</p>

        <form v-if="volunteersStore.myProfile" class="max-w-2xl space-y-4 rounded-md border border-line bg-white p-5" @submit.prevent="submit">
          <p class="text-sm text-muted">Teams: {{ volunteersStore.myProfile.teams?.map((team) => team.team_name).join(', ') || 'None assigned' }}</p>
          <input v-model="form.skills" placeholder="Skills (comma separated)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
          <textarea v-model="form.availability" rows="4" placeholder="Unavailable periods JSON or notes" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
          <input v-model="form.effective_from" type="date" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
          <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="volunteersStore.saving">
            Save volunteer profile
          </button>
        </form>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useVolunteersStore } from '../stores/volunteers'

const drawerOpen = ref(false)
const volunteersStore = useVolunteersStore()

const form = reactive({
  skills: '',
  availability: '',
  effective_from: new Date().toISOString().slice(0, 10),
})

watch(
  () => volunteersStore.myProfile,
  (profile) => {
    if (!profile) return
    form.skills = (profile.skills ?? []).join(', ')
    form.availability = JSON.stringify(profile.availability ?? {}, null, 2)
  },
  { immediate: true },
)

const submit = async () => {
  let availability = {}
  try {
    availability = form.availability ? JSON.parse(form.availability) : {}
  } catch {
    availability = { notes: form.availability }
  }

  await volunteersStore.updateMyProfile({
    skills: form.skills.split(',').map((skill) => skill.trim()).filter(Boolean),
    availability,
    effective_from: form.effective_from,
  })
}

onMounted(() => {
  volunteersStore.fetchMyProfile()
})
</script>
