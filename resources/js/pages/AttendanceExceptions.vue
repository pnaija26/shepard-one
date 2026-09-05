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
            <p class="truncate text-sm font-semibold text-ink">Attendance exceptions</p>
            <p class="truncate text-xs text-muted">Configured rules and detected concerns</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Pastoral care</p>
          <h1 class="font-serif text-3xl font-bold">Attendance exceptions</h1>
        </section>

        <p v-if="attendanceStore.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger">
          {{ attendanceStore.error }}
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Published rules</h2>
            <ul class="space-y-3 text-sm">
              <li v-for="rule in attendanceStore.rules" :key="rule.id" class="rounded-md border border-line p-3">
                <p class="font-medium">{{ rule.name }}</p>
                <p class="text-xs text-muted">{{ rule.rule_type }} · v{{ rule.current_version }} · {{ rule.status }}</p>
              </li>
            </ul>

            <form class="mt-6 space-y-3 border-t border-line pt-4" @submit.prevent="createRule">
              <input v-model="ruleForm.name" required placeholder="Rule name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <select v-model="ruleForm.rule_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option value="consecutive_absence">Consecutive absence</option>
                <option value="declining_attendance">Declining attendance</option>
                <option value="no_return_after_first_visit">No return after first visit</option>
                <option value="repeated_team_absence">Repeated team absence</option>
              </select>
              <input v-model="ruleForm.branch_id" type="number" placeholder="Branch ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="attendanceStore.saving">
                Create & publish rule
              </button>
            </form>
          </section>

          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Open exceptions</h2>
            <ul class="space-y-3 text-sm">
              <li v-for="item in attendanceStore.exceptions" :key="item.id" class="rounded-md border border-line p-3">
                <p class="font-medium">{{ item.subject_name || 'Person' }} · {{ item.rule_type }}</p>
                <p class="text-xs text-muted">{{ item.summary }}</p>
                <p class="mt-1 text-xs font-semibold uppercase tracking-wide" :class="statusClass(item.status)">{{ item.status }}</p>
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
import { useAttendanceStore } from '../stores/attendance'

const attendanceStore = useAttendanceStore()
const drawerOpen = ref(false)

const ruleForm = reactive({
  name: '',
  rule_type: 'consecutive_absence',
  branch_id: '',
  parameters: { consecutive_count: 3, lookback_days: 90 },
})

const statusClass = (status) => {
  if (status === 'open') return 'text-danger'
  if (status === 'flagged_review') return 'text-amber-600'
  return 'text-muted'
}

const createRule = async () => {
  await attendanceStore.createAndPublishRule({ ...ruleForm, branch_id: ruleForm.branch_id || null })
  ruleForm.name = ''
}

onMounted(async () => {
  await attendanceStore.fetchRules()
  await attendanceStore.fetchExceptions()
})
</script>
