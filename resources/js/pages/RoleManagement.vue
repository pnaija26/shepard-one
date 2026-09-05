<template>
  <div class="min-h-screen bg-canvas text-ink">
    <div v-if="drawerOpen" class="fixed inset-0 z-40 bg-ink/45 lg:hidden" aria-hidden="true" @click="drawerOpen = false"></div>

    <Sidebar v-model:drawer-open="drawerOpen" />

    <div class="lg:pl-60">
      <header class="sticky top-0 z-30 border-b border-line bg-white/95 backdrop-blur">
        <div class="flex min-h-18 items-center gap-3 px-4 sm:px-6 lg:px-8">
          <button type="button" class="grid size-11 shrink-0 place-items-center rounded-md border border-line text-ink hover:bg-canvas lg:hidden" aria-label="Open navigation" @click="drawerOpen = true">
            <Menu :size="20" aria-hidden="true" />
          </button>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-ink">Role Management</p>
            <p class="truncate text-xs text-muted">Manage roles and permissions</p>
          </div>
          <button type="button" class="grid size-11 place-items-center rounded-md text-muted hover:bg-canvas hover:text-ink" title="Sign out" aria-label="Sign out" @click="handleLogout">
            <LogOut :size="19" aria-hidden="true" />
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 flex flex-col gap-4 border-b border-line pb-6 sm:flex-row sm:items-end sm:justify-between" aria-labelledby="roles-heading">
          <div>
            <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Role Management</p>
            <h1 id="roles-heading" class="font-serif text-3xl font-bold">Roles & Permissions</h1>
            <p class="mt-1 text-sm text-muted">Create and manage user roles with scoped permissions</p>
          </div>
          <button type="button" class="inline-flex min-h-11 items-center justify-center gap-2 self-start rounded-md bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-hover sm:self-auto" @click="openCreateModal">
            <Plus :size="18" aria-hidden="true" /> Add Role
          </button>
        </section>

        <p v-if="roleStore.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger" role="alert">
          {{ roleStore.error }}
        </p>

        <div class="border border-line bg-white shadow-sm">
          <div class="px-4 py-4 sm:px-5">
            <h2 class="font-semibold">Roles</h2>
            <p class="mt-1 text-xs text-muted">Scoped permissions by organization, module, and action</p>
          </div>

          <div v-if="roleStore.loading" class="px-5 py-8 text-sm text-muted">Loading roles…</div>

          <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[680px] text-left text-sm">
              <thead class="bg-canvas/75 text-xs font-medium text-muted">
                <tr>
                  <th class="px-5 py-3" scope="col">Role Name</th>
                  <th class="px-5 py-3" scope="col">Description</th>
                  <th class="px-5 py-3" scope="col">Permissions</th>
                  <th class="px-5 py-3" scope="col">Actions</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-line">
                <tr v-for="role in roleStore.allRoles" :key="role.id" class="hover:bg-canvas/50">
                  <td class="px-5 py-3.5 font-medium">
                    {{ role.name }}
                    <span v-if="role.is_super_admin" class="ml-2 rounded-full bg-danger/10 px-2 py-0.5 text-[10px] font-semibold uppercase text-danger">Super</span>
                  </td>
                  <td class="px-5 py-3.5 text-muted">{{ role.description || '—' }}</td>
                  <td class="px-5 py-3.5 text-muted">
                    <div class="flex flex-wrap gap-1">
                      <span v-for="permission in (role.permissions || []).slice(0, 3)" :key="permission.id || permission.label" class="rounded-full bg-brand-soft px-2 py-1 text-xs font-medium text-brand">
                        {{ permission.label || permission.action }}
                      </span>
                      <span v-if="(role.permissions || []).length > 3" class="rounded-full bg-muted px-2 py-1 text-xs font-medium text-muted">
                        +{{ role.permissions.length - 3 }} more
                      </span>
                    </div>
                  </td>
                  <td class="px-5 py-3.5">
                    <button type="button" class="text-xs font-semibold text-danger hover:underline" @click="deleteRole(role)">Delete</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="showCreateRoleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
            <div class="flex items-center justify-between">
              <h3 class="text-lg font-semibold">Create New Role</h3>
              <button type="button" class="text-muted hover:text-ink" @click="showCreateRoleModal = false">
                <X :size="20" aria-hidden="true" />
              </button>
            </div>

            <form class="mt-4 space-y-4" @submit.prevent="createRole">
              <div>
                <label for="roleName" class="block text-sm font-medium text-ink">Role Name</label>
                <input id="roleName" v-model="newRole.name" type="text" required class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 shadow-sm focus:border-brand focus:outline-none focus:ring-brand sm:text-sm">
              </div>

              <div>
                <label for="roleDescription" class="block text-sm font-medium text-ink">Description</label>
                <textarea id="roleDescription" v-model="newRole.description" rows="2" class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 shadow-sm focus:border-brand focus:outline-none focus:ring-brand sm:text-sm"></textarea>
              </div>

              <div>
                <label class="block text-sm font-medium text-ink">Permissions</label>
                <div class="mt-2 space-y-2">
                  <div v-for="(permission, index) in newRole.permissions" :key="index" class="grid gap-2 sm:grid-cols-3">
                    <select v-model="permission.scope_type" class="rounded-md border border-line bg-white px-3 py-2 text-sm">
                      <option value="global">Global</option>
                      <option value="branch">Branch</option>
                      <option value="ministry">Ministry</option>
                      <option value="department">Department</option>
                      <option value="team">Team</option>
                      <option value="group">Group</option>
                    </select>
                    <input v-model="permission.scope_id" type="number" min="1" placeholder="Scope ID (org)" class="rounded-md border border-line bg-white px-3 py-2 text-sm" :disabled="permission.scope_type === 'global'">
                    <input v-model="permission.action" type="text" placeholder="Action (e.g. organizations.read)" required class="rounded-md border border-line bg-white px-3 py-2 text-sm">
                  </div>
                  <button type="button" class="text-sm font-medium text-brand hover:underline" @click="addPermission">+ Add Permission</button>
                </div>
              </div>

              <div class="flex justify-end gap-3 pt-4">
                <button type="button" class="rounded-md border border-line px-4 py-2 text-sm font-medium text-ink hover:bg-canvas" @click="showCreateRoleModal = false">Cancel</button>
                <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-medium text-white hover:bg-brand-hover" :disabled="roleStore.saving">
                  {{ roleStore.saving ? 'Creating…' : 'Create Role' }}
                </button>
              </div>
            </form>
          </div>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useRoleStore } from '../stores/roles'
import Sidebar from '@/components/Sidebar.vue'
import { Menu, LogOut, Plus, X } from '@lucide/vue'

const router = useRouter()
const authStore = useAuthStore()
const roleStore = useRoleStore()
const drawerOpen = ref(false)
const showCreateRoleModal = ref(false)

const emptyPermission = () => ({ scope_type: 'global', scope_id: null, action: '' })

const newRole = ref({
  name: '',
  description: '',
  permissions: [emptyPermission()],
})

const handleLogout = async () => {
  await authStore.logout()
  await router.replace('/login')
}

const openCreateModal = () => {
  newRole.value = { name: '', description: '', permissions: [emptyPermission()] }
  showCreateRoleModal.value = true
}

const addPermission = () => {
  newRole.value.permissions.push(emptyPermission())
}

const createRole = async () => {
  const payload = {
    name: newRole.value.name,
    description: newRole.value.description,
    permissions: newRole.value.permissions.map((p) => ({
      scope_type: p.scope_type,
      scope_id: p.scope_type === 'global' ? null : Number(p.scope_id) || null,
      action: p.action,
    })),
  }

  await roleStore.createRole(payload)
  showCreateRoleModal.value = false
}

const deleteRole = async (role) => {
  if (!confirm(`Delete role "${role.name}"?`)) {
    return
  }

  const breakGlass = role.is_super_admin ? prompt('Break-glass code required for super-admin role:') : null
  await roleStore.deleteRole(role.id, breakGlass ? { break_glass: breakGlass } : {})
}

onMounted(() => {
  roleStore.fetchRoles().catch(() => {})
})
</script>
