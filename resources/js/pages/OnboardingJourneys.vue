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
            <p class="truncate text-sm font-semibold text-ink">Onboarding journeys</p>
            <p class="truncate text-xs text-muted">Configure welcome sequences and monitor enrollments</p>
          </div>
          <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="onboardingStore.saving" @click="processDue">
            Process due steps
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Welcome</p>
          <h1 class="font-serif text-3xl font-bold">Onboarding journeys</h1>
        </section>

        <p v-if="onboardingStore.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger">
          {{ onboardingStore.error }}
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Published journeys</h2>
            <div v-if="onboardingStore.loading" class="text-sm text-muted">Loading…</div>
            <ul v-else class="space-y-3 text-sm">
              <li v-for="journey in onboardingStore.journeys" :key="journey.id" class="rounded-md border border-line p-3">
                <p class="font-medium">{{ journey.name }}</p>
                <p class="text-xs text-muted">{{ journey.trigger_event }} · v{{ journey.current_version }} · {{ journey.status }}</p>
              </li>
            </ul>

            <form class="mt-6 space-y-3 border-t border-line pt-4" @submit.prevent="createJourney">
              <input v-model="journeyForm.name" required placeholder="Journey name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <select v-model="journeyForm.trigger_event" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option value="visitor.captured">Visitor captured</option>
                <option value="member.registered">Member registered</option>
                <option value="member.lifecycle.convert">Lifecycle: convert</option>
                <option value="member.lifecycle.member">Lifecycle: member</option>
              </select>
              <input v-model="journeyForm.branch_id" type="number" placeholder="Branch ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="onboardingStore.saving">
                Create & publish default steps
              </button>
            </form>
          </section>

          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Enrollments</h2>
            <ul class="space-y-3 text-sm">
              <li v-for="enrollment in onboardingStore.enrollments" :key="enrollment.id" class="rounded-md border border-line p-3">
                <p class="font-medium">{{ enrollment.subject_name }} · {{ enrollment.journey?.name }}</p>
                <p class="text-xs text-muted">Version {{ enrollment.journey_version }} · {{ enrollment.status }}</p>
                <ul class="mt-2 space-y-1 text-xs text-muted">
                  <li v-for="step in enrollment.steps" :key="step.step_key">
                    Day {{ step.day_offset }} {{ step.step_key }} — {{ step.status }}
                  </li>
                </ul>
              </li>
            </ul>
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
import { useOnboardingStore } from '../stores/onboarding'

const onboardingStore = useOnboardingStore()
const drawerOpen = ref(false)

const journeyForm = reactive({
  name: '',
  trigger_event: 'visitor.captured',
  branch_id: '',
  steps: [
    { key: 'day_0_welcome', day_offset: 0, action_type: 'message', channel: 'email', message: 'Welcome' },
    { key: 'day_1_task', day_offset: 1, action_type: 'task', title: 'Follow up call' },
    { key: 'day_3_reminder', day_offset: 3, action_type: 'reminder', channel: 'in_app', message: 'Reminder' },
  ],
})

const createJourney = async () => {
  await onboardingStore.createAndPublishJourney({ ...journeyForm, branch_id: journeyForm.branch_id || null })
  journeyForm.name = ''
}

const processDue = async () => {
  await onboardingStore.processDue()
}

onMounted(async () => {
  await onboardingStore.fetchJourneys()
  await onboardingStore.fetchEnrollments()
})
</script>
