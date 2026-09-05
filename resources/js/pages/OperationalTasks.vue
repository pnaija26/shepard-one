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
            <p class="truncate text-sm font-semibold text-ink">Operational tasks</p>
            <p class="truncate text-xs text-muted">Assign, track, and complete work with due dates</p>
          </div>
          <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="taskStore.saving" @click="runOverdue">
            Process overdue
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Automation</p>
          <h1 class="font-serif text-3xl font-bold">Tasks</h1>
        </section>

        <p v-if="taskStore.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ taskStore.error }}</p>
        <p v-if="taskStore.overdueResult" class="mb-4 rounded-md border border-line bg-white px-4 py-3 text-sm text-muted">
          Overdue run: marked {{ taskStore.overdueResult.marked_overdue }}, reminded {{ taskStore.overdueResult.reminded }}, skipped {{ taskStore.overdueResult.skipped }}
        </p>

        <div class="grid gap-6 lg:grid-cols-2">
          <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createTask">
            <h2 class="font-semibold">Create task</h2>
            <input v-model="form.title" required placeholder="Title" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <textarea v-model="form.description" required rows="3" placeholder="Description" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
            <input v-model="form.branch_id" type="number" required placeholder="Branch ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.department" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="operations">Operations</option>
              <option value="pastoral">Pastoral</option>
              <option value="worship">Worship</option>
              <option value="children">Children</option>
              <option value="youth">Youth</option>
              <option value="finance">Finance</option>
              <option value="facilities">Facilities</option>
              <option value="communications">Communications</option>
              <option value="other">Other</option>
            </select>
            <input v-model="form.assignee_id" type="number" required placeholder="Assignee user ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <input v-model="form.due_date" type="date" required class="w-full rounded-md border border-line px-3 py-2 text-sm" />
            <select v-model="form.priority" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <option value="low">Low</option>
              <option value="normal">Normal</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </select>
            <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="taskStore.saving">Create task</button>
          </form>

          <section class="space-y-6">
            <div class="rounded-md border border-line bg-white p-5">
              <h2 class="mb-3 font-semibold">Open queue</h2>
              <ul class="space-y-2 text-sm">
                <li v-for="item in taskStore.tasks" :key="item.id" class="rounded-md border border-line p-3">
                  <button type="button" class="w-full text-left" @click="openTask(item.id)">
                    <p class="font-medium">{{ item.reference }} · {{ item.title }}</p>
                    <p class="text-xs text-muted">{{ item.status }} · due {{ item.due_date }} · {{ item.assignee?.name ?? '—' }}</p>
                  </button>
                </li>
              </ul>
            </div>

            <section v-if="taskStore.selectedTask" class="rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="font-semibold">{{ taskStore.selectedTask.reference }}</h2>
              <p class="text-xs text-muted">{{ taskStore.selectedTask.department_label }} · {{ taskStore.selectedTask.status }} · {{ taskStore.selectedTask.priority }}</p>
              <p class="mt-2 whitespace-pre-wrap">{{ taskStore.selectedTask.description }}</p>
              <p class="mt-2 text-xs text-muted">Assignee: {{ taskStore.selectedTask.assignee?.name ?? '—' }} · Due {{ taskStore.selectedTask.due_date }}</p>

              <div class="mt-4 space-y-2 border-t border-line pt-3">
                <h3 class="font-medium">Update status</h3>
                <select v-model="statusForm.status" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                  <option value="open">Open</option>
                  <option value="in_progress">In progress</option>
                  <option value="pending">Pending</option>
                  <option value="completed">Completed</option>
                  <option value="cancelled">Cancelled</option>
                </select>
                <textarea v-model="statusForm.notes" rows="2" placeholder="Notes" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
                <input v-if="statusForm.status === 'completed'" v-model="statusForm.evidence_filename" placeholder="Completion evidence filename" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <button type="button" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="taskStore.saving" @click="updateStatus">Save status</button>
              </div>

              <div v-if="taskStore.selectedTask.transitions?.length" class="mt-4 space-y-1 border-t border-line pt-3 text-xs text-muted">
                <h3 class="mb-1 text-sm font-medium text-ink">Transitions</h3>
                <p v-for="row in taskStore.selectedTask.transitions" :key="row.id">
                  {{ row.from_status || '—' }} → {{ row.to_status }} · {{ row.actor?.name || 'system' }}
                </p>
              </div>
            </section>
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
import { useOperationalTasksStore } from '../stores/operationalTasks'

const drawerOpen = ref(false)
const taskStore = useOperationalTasksStore()

const form = reactive({
  title: '',
  description: '',
  branch_id: '',
  department: 'operations',
  assignee_id: '',
  due_date: '',
  priority: 'normal',
})

const statusForm = reactive({
  status: 'in_progress',
  notes: '',
  evidence_filename: 'completion.pdf',
})

async function createTask() {
  await taskStore.createTask({
    title: form.title,
    description: form.description,
    branch_id: Number(form.branch_id),
    department: form.department,
    assignee_id: Number(form.assignee_id),
    due_date: form.due_date,
    priority: form.priority,
  })
  form.title = ''
  form.description = ''
}

async function openTask(id) {
  await taskStore.selectTask(id)
}

async function updateStatus() {
  if (!taskStore.selectedTask) return
  const payload = {
    status: statusForm.status,
    notes: statusForm.notes || undefined,
  }
  if (statusForm.status === 'completed') {
    payload.completion_evidence = [{
      filename: statusForm.evidence_filename || 'completion.pdf',
      mime_type: 'application/pdf',
      size_bytes: 1024,
      content_hash: 'ui-placeholder-hash',
    }]
  }
  await taskStore.changeStatus(taskStore.selectedTask.id, payload)
}

async function runOverdue() {
  await taskStore.processOverdue()
}

onMounted(() => taskStore.fetchTasks())
</script>
