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
            <p class="truncate text-sm font-semibold text-ink">Service teams</p>
            <p class="truncate text-xs text-muted">Configure teams, staffing rules, and reporting</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Volunteers</p>
          <h1 class="font-serif text-3xl font-bold">Service teams</h1>
        </section>

        <p v-if="teamsStore.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ teamsStore.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <section class="rounded-md border border-line bg-white p-5">
            <h2 class="mb-3 font-semibold">Teams</h2>
            <ul class="space-y-3 text-sm">
              <li v-for="team in teamsStore.teams" :key="team.id" class="rounded-md border border-line p-3" :class="teamsStore.selectedTeamId === team.id ? 'border-brand' : ''">
                <button type="button" class="w-full text-left" @click="teamsStore.selectTeam(team.id)">
                  <p class="font-medium">{{ team.name }} · v{{ team.current_config_version }}</p>
                  <p class="text-xs text-muted">{{ team.category_label }} · {{ team.status }}</p>
                </button>
                <div class="mt-2 flex gap-2">
                  <button v-if="team.status === 'draft'" type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="teamsStore.activateTeam(team.id)">Activate</button>
                  <button v-if="team.status !== 'archived'" type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="teamsStore.archiveTeam(team.id)">Archive</button>
                </div>
              </li>
            </ul>
          </section>

          <div class="space-y-6">
            <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="submit">
              <h2 class="font-semibold">Create team</h2>
              <input v-model="form.name" required placeholder="Team name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="form.branch_id" type="number" required placeholder="Branch ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="form.leader_user_id" type="number" required placeholder="Lead user ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <select v-model="form.category" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option value="worship">Worship</option>
                <option value="media">Media & production</option>
                <option value="ushering">Ushering</option>
                <option value="children">Children ministry</option>
                <option value="security">Security</option>
              </select>
              <textarea v-model="form.description" rows="3" placeholder="Description" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="teamsStore.saving">
                Create team
              </button>
            </form>

            <section v-if="teamsStore.selectedTeamId" class="space-y-3 rounded-md border border-line bg-white p-5">
              <h2 class="font-semibold">Team assignments</h2>
              <ul v-if="teamsStore.assignments.length" class="space-y-2 text-sm">
                <li v-for="assignment in teamsStore.assignments" :key="assignment.id" class="rounded-md border border-line p-3">
                  <p class="font-medium">{{ assignment.member?.full_name ?? 'Member' }} · {{ assignment.team_role }}</p>
                  <p class="text-xs text-muted">{{ assignment.shift_label || 'No shift' }} · {{ assignment.status }}</p>
                  <div class="mt-2 flex gap-2">
                    <button v-if="assignment.status === 'pending'" type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="teamsStore.approveAssignment(assignment.id)">Approve</button>
                    <button v-if="assignment.status !== 'removed'" type="button" class="rounded-md border border-line px-2 py-1 text-xs" @click="teamsStore.removeAssignment(assignment.id, 'Removed from roster')">Remove</button>
                  </div>
                </li>
              </ul>
              <p v-else class="text-sm text-muted">No active assignments yet.</p>

              <form class="space-y-2 border-t border-line pt-4" @submit.prevent="submitAssignment">
                <h3 class="text-sm font-semibold">Assign member</h3>
                <input v-model="assignmentForm.member_id" type="number" required placeholder="Member ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <select v-model="assignmentForm.team_role" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                  <option value="member">Member</option>
                  <option value="lead">Lead</option>
                  <option value="assistant">Assistant</option>
                  <option value="trainee">Trainee</option>
                </select>
                <input v-model="assignmentForm.sub_team" placeholder="Sub-team" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <input v-model="assignmentForm.shift_label" placeholder="Shift label" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <input v-model="assignmentForm.effective_from" type="date" required class="w-full rounded-md border border-line px-3 py-2 text-sm" />
                <label class="flex items-center gap-2 text-xs">
                  <input v-model="assignmentForm.override" type="checkbox" />
                  Override policy conflicts
                </label>
                <textarea v-if="assignmentForm.override" v-model="assignmentForm.override_reason" rows="2" placeholder="Override reason" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea>
                <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="teamsStore.saving">
                  Assign member
                </button>
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
import { useServiceTeamsStore } from '../stores/serviceTeams'

const drawerOpen = ref(false)
const teamsStore = useServiceTeamsStore()

const form = reactive({
  name: '',
  branch_id: '',
  leader_user_id: '',
  category: 'worship',
  description: '',
})

const assignmentForm = reactive({
  member_id: '',
  team_role: 'member',
  sub_team: '',
  shift_label: '',
  effective_from: new Date().toISOString().slice(0, 10),
  override: false,
  override_reason: '',
})

const submit = async () => {
  const leaderId = Number(form.leader_user_id)
  await teamsStore.createTeam({
    branch_id: Number(form.branch_id),
    name: form.name,
    category: form.category,
    description: form.description,
    leaders: [{ user_id: leaderId, role: 'lead' }],
    required_skills: ['serving'],
    minimum_staffing: { minimum_per_session: 3, maximum_per_session: 8 },
    schedules: [{ type: 'weekly', label: 'Sunday service', required_volunteers: 4 }],
    objectives: ['Serve faithfully each week.'],
    attendance_rules: { require_check_in: true, methods: ['manual', 'qr'] },
    reporting_template: { frequency: 'weekly', fields: ['attendance', 'issues'] },
    approval_hierarchy: {
      requires_approval: true,
      levels: [{ user_id: leaderId, role: 'team_lead' }],
    },
  })
}

const submitAssignment = async () => {
  if (!teamsStore.selectedTeamId) {
    return
  }

  await teamsStore.assignMember(teamsStore.selectedTeamId, {
    member_id: Number(assignmentForm.member_id),
    team_role: assignmentForm.team_role,
    sub_team: assignmentForm.sub_team || null,
    shift_label: assignmentForm.shift_label || null,
    responsibilities: [],
    effective_from: assignmentForm.effective_from,
    override: assignmentForm.override,
    override_reason: assignmentForm.override ? assignmentForm.override_reason : null,
  })

  assignmentForm.member_id = ''
  assignmentForm.sub_team = ''
  assignmentForm.shift_label = ''
  assignmentForm.override = false
  assignmentForm.override_reason = ''
}

onMounted(() => {
  teamsStore.fetchTeams()
})
</script>
