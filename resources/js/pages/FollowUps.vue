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
            <p class="truncate text-sm font-semibold text-ink">Follow-up work</p>
            <p class="truncate text-xs text-muted">Assigned tasks, outcomes, and escalations</p>
          </div>
          <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="followUpStore.saving" @click="processEscalations">
            Process escalations
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Pastoral care</p>
          <h1 class="font-serif text-3xl font-bold">Follow-up tasks</h1>
        </section>

        <p v-if="followUpStore.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger">
          {{ followUpStore.error }}
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Open assignments</h2>
            <ul class="space-y-3 text-sm">
              <li v-for="item in followUpStore.followUps" :key="item.id" class="rounded-md border border-line p-3">
                <p class="font-medium">{{ item.person_name || 'Person' }} · {{ item.priority }}</p>
                <p class="text-xs text-muted">Due {{ item.due_date }} · {{ item.status }}</p>
                <p class="mt-1 text-xs">{{ item.reason }}</p>
              </li>
            </ul>
          </section>

          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Create follow-up</h2>
            <form class="space-y-3" @submit.prevent="createFollowUp">
              <input v-model="form.reason" required placeholder="Reason" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="form.person_id" type="number" required placeholder="Person ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="form.assignee_id" type="number" required placeholder="Assignee user ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="form.branch_id" type="number" placeholder="Branch ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="form.due_date" type="date" required class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <select v-model="form.contact_method" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option value="phone">Phone</option>
                <option value="email">Email</option>
                <option value="visit">Visit</option>
              </select>
              <select v-model="form.priority" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option value="normal">Normal</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
              </select>
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="followUpStore.saving">
                Assign follow-up
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
import { useFollowUpStore } from '../stores/followUps'

const followUpStore = useFollowUpStore()
const drawerOpen = ref(false)

const form = reactive({
  person_type: 'App\\Models\\Visitor',
  person_id: '',
  branch_id: '',
  reason: '',
  assignee_id: '',
  due_date: '',
  contact_method: 'phone',
  priority: 'normal',
  source_type: 'manual',
})

const createFollowUp = async () => {
  await followUpStore.createFollowUp({ ...form, branch_id: form.branch_id || null })
  form.reason = ''
}

const processEscalations = async () => {
  await followUpStore.processEscalations()
}

onMounted(() => followUpStore.fetchFollowUps())
</script>
