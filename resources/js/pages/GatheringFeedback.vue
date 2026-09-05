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
            <p class="truncate text-sm font-semibold text-ink">Gathering feedback</p>
            <p class="truncate text-xs text-muted">Submit and manage service or event feedback</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Operations</p>
          <h1 class="font-serif text-3xl font-bold">Feedback</h1>
        </section>

        <p v-if="feedbackStore.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ feedbackStore.error }}</p>
        <p v-if="feedbackStore.message" class="mb-4 rounded-md border border-line bg-white px-4 py-3 text-sm">{{ feedbackStore.message }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-4 rounded-md border border-line bg-white p-5" @submit.prevent="submit">
            <h2 class="font-semibold">Submit feedback</h2>
            <select v-model="form.gathering_key" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="church_service">Church service</option>
              <option value="church_event">Church event</option>
            </select>
            <input v-model="form.gathering_id" type="number" required placeholder="Gathering ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.category" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="facilities">Facilities</option>
              <option value="sound">Sound</option>
              <option value="media">Media</option>
              <option value="ushering">Ushering</option>
              <option value="children">Children</option>
              <option value="parking">Parking</option>
              <option value="security">Security</option>
              <option value="general_experience">General experience</option>
            </select>
            <textarea v-model="form.body" required rows="4" placeholder="Share your feedback" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
            <select v-model="form.identity_mode" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="identified">Share my name</option>
              <option value="anonymous">Submit anonymously</option>
            </select>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="form.consent_feedback_notifications" type="checkbox" />
              Notify me when my feedback is reviewed
            </label>
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="feedbackStore.saving">
              Submit feedback
            </button>
          </form>

          <section class="rounded-md border border-line bg-white p-5">
            <div class="mb-3 flex items-center justify-between">
              <h2 class="font-semibold">Team queue</h2>
              <button type="button" class="text-xs font-semibold text-brand" @click="feedbackStore.fetchFeedback()">Refresh</button>
            </div>
            <ul class="space-y-3 text-sm">
              <li v-for="item in feedbackStore.items" :key="item.id" class="rounded-md border border-line p-3">
                <p class="font-medium">{{ item.category_label }} · {{ item.status }}</p>
                <p class="text-xs text-muted">{{ item.assigned_team_label }} · {{ item.submitter_display_name }}</p>
                <p class="mt-1">{{ item.body }}</p>
                <div v-if="item.status !== 'closed'" class="mt-2 flex gap-2">
                  <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="acknowledge(item.id)">Acknowledge</button>
                  <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="close(item.id)">Close</button>
                </div>
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
import { useFeedbackStore } from '../stores/feedback'

const drawerOpen = ref(false)
const feedbackStore = useFeedbackStore()

const form = reactive({
  gathering_key: 'church_service',
  gathering_id: '',
  category: 'general_experience',
  body: '',
  identity_mode: 'identified',
  consent_feedback_notifications: false,
})

const submit = async () => {
  await feedbackStore.submitFeedback({
    ...form,
    gathering_id: Number(form.gathering_id),
  })
}

const acknowledge = async (id) => {
  await feedbackStore.recordActivity(id, {
    activity_type: 'acknowledged',
    notes: 'Acknowledged from feedback queue.',
  })
}

const close = async (id) => {
  await feedbackStore.recordActivity(id, {
    activity_type: 'closed',
    notes: 'Closed from feedback queue.',
    notify_submitter: true,
  })
}

onMounted(() => {
  feedbackStore.fetchFeedback()
})
</script>
