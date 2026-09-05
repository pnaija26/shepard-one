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
            <p class="truncate text-sm font-semibold text-ink">Training offerings</p>
            <p class="truncate text-xs text-muted">Courses, schedules, materials, and enrolment</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Discipleship</p>
          <h1 class="font-serif text-3xl font-bold">Training offerings</h1>
        </section>

        <p v-if="trainingStore.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ trainingStore.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Offerings</h2>
            <ul class="space-y-2 text-sm">
              <li v-for="offering in trainingStore.offerings" :key="offering.id" class="rounded-md border border-line p-3">
                <button type="button" class="w-full text-left" @click="trainingStore.selectOffering(offering.id)">
                  <p class="font-medium">{{ offering.name }} · {{ offering.course_type }}</p>
                  <p class="text-xs text-muted">{{ offering.status }} · v{{ offering.current_version }}</p>
                </button>
              </li>
            </ul>
          </section>

          <div class="space-y-6">
            <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createOffering">
              <h2 class="font-semibold">Create offering</h2>
              <input v-model="createForm.name" required placeholder="Course name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <select v-model="createForm.course_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option value="membership">Membership</option>
                <option value="new_believer">New believer</option>
                <option value="leadership">Leadership</option>
                <option value="volunteer">Volunteer</option>
                <option value="ministry">Ministry</option>
              </select>
              <input v-model="createForm.capacity" type="number" min="1" placeholder="Capacity" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="trainingStore.saving">Create draft</button>
            </form>

            <section v-if="trainingStore.selectedOffering" class="space-y-3 rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="font-semibold">{{ trainingStore.selectedOffering.name }}</h2>
              <p>{{ trainingStore.selectedOffering.description }}</p>
              <p>Status: {{ trainingStore.selectedOffering.status }} · Capacity: {{ trainingStore.selectedOffering.capacity }}</p>
              <button v-if="trainingStore.selectedOffering.status === 'draft'" type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="publishSelected">Publish</button>

              <div v-if="trainingStore.selectedOffering.published_config">
                <p class="font-medium">Sessions</p>
                <ul class="space-y-1">
                  <li v-for="session in trainingStore.selectedOffering.published_config.sessions" :key="session.title">
                    {{ session.title }} · {{ session.scheduled_at }}
                  </li>
                </ul>
                <p class="mt-2 font-medium">Materials</p>
                <ul class="space-y-1">
                  <li v-for="material in trainingStore.selectedOffering.published_config.materials" :key="material.title">
                    {{ material.title }} · {{ material.access_level }}
                  </li>
                </ul>
              </div>

              <div class="border-t border-line pt-3">
                <p class="font-medium">Enrolments</p>
                <ul class="space-y-2">
                  <li v-for="enrolment in trainingStore.enrolments" :key="enrolment.id" class="rounded-md border border-line p-2">
                    <button type="button" class="w-full text-left" @click="loadProgress(enrolment.id)">
                      {{ enrolment.member?.full_name }} · {{ enrolment.status }}
                    </button>
                  </li>
                </ul>
                <form class="mt-3 space-y-2" @submit.prevent="enrolMember">
                  <input v-model="enrolForm.member_id" type="number" required placeholder="Member ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                  <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white">Request enrolment</button>
                </form>
              </div>

              <section v-if="trainingStore.selectedProgress" class="space-y-2 border-t border-line pt-3">
                <h3 class="font-medium">Learner progress</h3>
                <p>Progress: {{ trainingStore.selectedProgress.progress_percent }}% · Requirements met: {{ trainingStore.selectedProgress.requirements_met ? 'Yes' : 'No' }}</p>
                <ul v-if="trainingStore.selectedProgress.unmet_criteria?.length" class="text-xs text-muted">
                  <li v-for="(item, index) in trainingStore.selectedProgress.unmet_criteria" :key="index">{{ item.detail }}</li>
                </ul>
                <p v-if="trainingStore.selectedProgress.certificate">Certificate: {{ trainingStore.selectedProgress.certificate.certificate_reference }} · {{ trainingStore.selectedProgress.certificate.status }}</p>
                <button v-if="trainingStore.selectedProgress.requirements_met && !trainingStore.selectedProgress.certificate" type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="confirmCompletion">Confirm completion</button>
              </section>
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
import { useTrainingOfferingsStore } from '../stores/trainingOfferings'

const drawerOpen = ref(false)
const trainingStore = useTrainingOfferingsStore()

const createForm = reactive({
  name: '',
  course_type: 'new_believer',
  capacity: 20,
})

const enrolForm = reactive({
  member_id: '',
})

async function createOffering() {
  await trainingStore.createOffering({
    branch_id: 1,
    name: createForm.name,
    course_type: createForm.course_type,
    description: 'Created from training UI.',
    capacity: Number(createForm.capacity),
    waitlist_enabled: true,
    sessions: [{
      title: 'Session 1',
      scheduled_at: new Date(Date.now() + 7 * 86400000).toISOString(),
      location: 'Training room',
      duration_minutes: 90,
    }],
    facilitators: [{ name: 'Course facilitator', role: 'lead', email: 'facilitator@example.com' }],
    assessments: [{ title: 'Course assessment', type: 'reflection', required: true }],
    materials: [
      { title: 'Course overview', url: 'https://example.com/overview', access_level: 'public' },
      { title: 'Participant workbook', url: 'https://example.com/workbook', access_level: 'enrolled' },
    ],
    completion_rules: { min_attendance_sessions: 1 },
    enrolment_rules: { lifecycle_stages: ['member'], min_age: 18, requires_consent: true },
    prerequisites: { required_offering_ids: [] },
  })
}

async function publishSelected() {
  if (!trainingStore.selectedOffering) return
  await trainingStore.publishOffering(trainingStore.selectedOffering.id)
}

async function enrolMember() {
  if (!trainingStore.selectedOffering) return
  await trainingStore.enrolMember(trainingStore.selectedOffering.id, {
    member_id: Number(enrolForm.member_id),
  })
}

async function loadProgress(enrolmentId) {
  await trainingStore.fetchProgress(enrolmentId)
}

async function confirmCompletion() {
  if (!trainingStore.selectedProgress?.enrolment_id) return
  await trainingStore.confirmCompletion(trainingStore.selectedProgress.enrolment_id)
}

onMounted(() => {
  trainingStore.fetchOfferings()
})
</script>
