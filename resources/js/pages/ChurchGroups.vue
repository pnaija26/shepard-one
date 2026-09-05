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
            <p class="truncate text-sm font-semibold text-ink">Church groups</p>
            <p class="truncate text-xs text-muted">Cells, fellowships, classes, and interest groups</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Discipleship</p>
          <h1 class="font-serif text-3xl font-bold">Church groups</h1>
        </section>

        <p v-if="groupsStore.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ groupsStore.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Groups</h2>
            <ul class="space-y-2 text-sm">
              <li v-for="group in groupsStore.groups" :key="group.id" class="rounded-md border border-line p-3">
                <button type="button" class="w-full text-left" @click="groupsStore.selectGroup(group.id)">
                  <p class="font-medium">{{ group.name }} · {{ group.group_type }}</p>
                  <p class="text-xs text-muted">{{ group.status }} · {{ group.active_member_count }} members</p>
                </button>
              </li>
            </ul>
          </section>

          <div class="space-y-6">
            <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createGroup">
              <h2 class="font-semibold">Create group</h2>
              <input v-model="createForm.name" required placeholder="Group name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <select v-model="createForm.group_type" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option value="cell">Cell</option>
                <option value="fellowship">Fellowship</option>
                <option value="class">Class</option>
                <option value="interest_group">Interest group</option>
              </select>
              <input v-model="createForm.leader_user_id" type="number" required placeholder="Leader user ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="createForm.capacity" type="number" min="1" placeholder="Capacity" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="groupsStore.saving">Create draft</button>
            </form>

            <section v-if="groupsStore.selectedGroup" class="space-y-3 rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="font-semibold">{{ groupsStore.selectedGroup.name }}</h2>
              <p>{{ groupsStore.selectedGroup.description }}</p>
              <p>Status: {{ groupsStore.selectedGroup.status }} · Capacity: {{ groupsStore.selectedGroup.capacity }}</p>
              <button v-if="groupsStore.selectedGroup.status === 'draft'" type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="activateSelected">Activate</button>
              <ul class="space-y-2">
                <li v-for="membership in groupsStore.selectedGroup.memberships" :key="membership.id" class="rounded-md border border-line p-2">
                  {{ membership.member?.full_name }} · {{ membership.role }} · {{ membership.status }}
                </li>
              </ul>
              <div v-if="groupsStore.selectedGroup.pending_join_requests?.length">
                <p class="font-medium">Pending join requests</p>
                <div v-for="request in groupsStore.selectedGroup.pending_join_requests" :key="request.id" class="mt-2 flex items-center justify-between gap-2">
                  <span>{{ request.member_name }}</span>
                  <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="approveRequest(request.id)">Approve</button>
                </div>
              </div>
              <form class="space-y-2 border-t border-line pt-3" @submit.prevent="assignMember">
                <input v-model="memberForm.member_id" type="number" required placeholder="Member ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white">Assign member</button>
              </form>

              <section v-if="groupsStore.meetingDashboard" class="space-y-2 border-t border-line pt-3">
                <h3 class="font-medium">Meeting dashboard</h3>
                <p>Completed meetings: {{ groupsStore.meetingDashboard.completed_meetings }}</p>
                <p>Attendance rate: {{ groupsStore.meetingDashboard.attendance_rate ?? '—' }}%</p>
                <p>Corrected records: {{ groupsStore.meetingDashboard.corrected_attendance_records }}</p>
                <p>Open follow-ups: {{ groupsStore.meetingDashboard.open_follow_ups }}</p>
                <p>Actions: {{ groupsStore.meetingDashboard.completed_actions }} completed / {{ groupsStore.meetingDashboard.pending_actions }} pending</p>
              </section>

              <section class="space-y-2 border-t border-line pt-3">
                <h3 class="font-medium">Meetings</h3>
                <ul class="space-y-2">
                  <li v-for="meeting in groupsStore.meetings" :key="meeting.id" class="rounded-md border border-line p-2">
                    {{ meeting.title }} · {{ meeting.status }} · {{ meeting.scheduled_at }}
                  </li>
                </ul>
                <form class="space-y-2" @submit.prevent="scheduleMeeting">
                  <input v-model="meetingForm.title" required placeholder="Meeting title" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                  <input v-model="meetingForm.scheduled_at" type="datetime-local" required class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                  <button type="submit" class="rounded-md border border-line px-3 py-2 text-xs">Schedule meeting</button>
                </form>
                <form v-if="groupsStore.meetings.length" class="space-y-2" @submit.prevent="recordMeeting">
                  <input v-model="recordForm.meeting_id" type="number" required placeholder="Meeting ID to record" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                  <input v-model="recordForm.member_id" type="number" required placeholder="Member ID for attendance" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                  <textarea v-model="recordForm.notes" placeholder="Meeting notes" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
                  <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white">Record activity</button>
                </form>
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
import { useChurchGroupsStore } from '../stores/groups'

const drawerOpen = ref(false)
const groupsStore = useChurchGroupsStore()

const createForm = reactive({
  name: '',
  group_type: 'fellowship',
  leader_user_id: '',
  capacity: 20,
})

const memberForm = reactive({
  member_id: '',
})

const meetingForm = reactive({
  title: '',
  scheduled_at: '',
})

const recordForm = reactive({
  meeting_id: '',
  member_id: '',
  notes: '',
})

async function createGroup() {
  await groupsStore.createGroup({
    name: createForm.name,
    branch_id: 1,
    group_type: createForm.group_type,
    description: 'Created from groups UI.',
    leaders: [{ user_id: Number(createForm.leader_user_id), role: 'lead' }],
    meeting_pattern: {
      frequency: 'weekly',
      day: 'sunday',
      start_time: '10:00',
      end_time: '12:00',
      venue: 'Main hall',
    },
    capacity: Number(createForm.capacity),
    eligibility: { min_age: 18, lifecycle_stages: ['member'], requires_consent: true },
    communication_settings: { allow_member_posts: true },
    reporting_settings: { requires_weekly_report: false },
  })
}

async function activateSelected() {
  if (!groupsStore.selectedGroup) return
  await groupsStore.activateGroup(groupsStore.selectedGroup.id)
}

async function assignMember() {
  if (!groupsStore.selectedGroup) return
  await groupsStore.assignMember(groupsStore.selectedGroup.id, {
    member_id: Number(memberForm.member_id),
    role: 'member',
  })
}

async function approveRequest(requestId) {
  if (!groupsStore.selectedGroup) return
  await groupsStore.approveJoinRequest(requestId, groupsStore.selectedGroup.id)
}

async function scheduleMeeting() {
  if (!groupsStore.selectedGroup) return
  await groupsStore.scheduleMeeting(groupsStore.selectedGroup.id, {
    title: meetingForm.title,
    scheduled_at: new Date(meetingForm.scheduled_at).toISOString(),
    location: 'Group venue',
  })
  meetingForm.title = ''
  meetingForm.scheduled_at = ''
}

async function recordMeeting() {
  await groupsStore.recordMeeting(Number(recordForm.meeting_id), {
    notes: recordForm.notes,
    attendance: [{ member_id: Number(recordForm.member_id), status: 'present' }],
  })
  recordForm.notes = ''
}

onMounted(() => {
  groupsStore.fetchGroups()
})
</script>
