<template>
  <div class="min-h-screen bg-canvas text-ink">
    <div v-if="drawerOpen" class="fixed inset-0 z-40 bg-ink/45 lg:hidden" aria-hidden="true" @click="drawerOpen = false"></div>

    <Sidebar :drawer-open="drawerOpen" />

    <div class="lg:pl-60">
      <header class="sticky top-0 z-30 border-b border-line bg-white/95 backdrop-blur">
        <div class="flex min-h-18 items-center gap-3 px-4 sm:px-6 lg:px-8">
          <button type="button" class="grid size-11 shrink-0 place-items-center rounded-md border border-line text-ink hover:bg-canvas lg:hidden" aria-label="Open navigation" aria-controls="primary-navigation" :aria-expanded="drawerOpen" @click="drawerOpen = true">
            <Menu :size="20" aria-hidden="true" />
          </button>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-ink">Role Management</p>
            <p class="truncate text-xs text-muted">Manage roles and permissions</p>
          </div>
          <button type="button" class="relative grid size-11 place-items-center rounded-md text-muted hover:bg-canvas hover:text-ink" aria-label="Notifications">
            <Bell :size="20" aria-hidden="true" />
            <span class="absolute right-2.5 top-2.5 size-2 rounded-full bg-danger ring-2 ring-white"></span>
          </button>
          <div class="hidden h-8 w-px bg-line sm:block"></div>
          <div class="hidden min-w-0 sm:block">
            <p class="max-w-40 truncate text-sm font-semibold">{{ userName }}</p>
            <p class="max-w-40 truncate text-xs text-muted">{{ roleLabel }}</p>
          </div>
          <div class="grid size-9 shrink-0 place-items-center rounded-full bg-brand text-xs font-semibold text-white" aria-hidden="true">
            {{ initials }}
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
          <button type="button" class="inline-flex min-h-11 items-center justify-center gap-2 self-start rounded-md bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-hover sm:self-auto" @click="showCreateRoleModal = true">
            <Plus :size="18" aria-hidden="true" /> Add Role
          </button>
        </section>

        <!-- Roles Table -->
        <div class="border border-line bg-white shadow-sm">
          <div class="px-4 py-4 sm:px-5">
            <h2 class="font-semibold">Roles</h2>
            <p class="mt-1 text-xs text-muted">Manage available roles and their permissions</p>
          </div>
          <div class="overflow-x-auto">
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
                <tr v-for="role in roles" :key="role.id" class="hover:bg-canvas/50">
                  <td class="px-5 py-3.5 font-medium">{{ role.name }}</td>
                  <td class="px-5 py-3.5 text-muted">{{ role.description }}</td>
                  <td class="px-5 py-3.5 text-muted">
                    <div class="flex flex-wrap gap-1">
                      <span v-for="permission in role.permissions.slice(0, 3)" :key="permission" class="rounded-full bg-brand-soft px-2 py-1 text-xs font-medium text-brand">{{ permission }}</span>
                      <span v-if="role.permissions.length > 3" class="rounded-full bg-muted px-2 py-1 text-xs font-medium text-muted">+{{ role.permissions.length - 3 }} more</span>
                    </div>
                  </td>
                  <td class="px-5 py-3.5">
                    <div class="flex gap-2">
                      <button type="button" class="text-xs font-semibold text-brand hover:underline" @click="editRole(role)">Edit</button>
                      <button type="button" class="text-xs font-semibold text-danger hover:underline" @click="deleteRole(role)">Delete</button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Create Role Modal -->
        <div v-if="showCreateRoleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
          <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <div class="flex items-center justify-between">
              <h3 class="text-lg font-semibold">Create New Role</h3>
              <button type="button" @click="showCreateRoleModal = false" class="text-muted hover:text-ink">
                <X :size="20" aria-hidden="true" />
              </button>
            </div>
            
            <form @submit.prevent="createRole" class="mt-4 space-y-4">
              <div>
                <label for="roleName" class="block text-sm font-medium text-ink">Role Name</label>
                <input type="text" id="roleName" v-model="newRole.name" required class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 shadow-sm focus:border-brand focus:outline-none focus:ring-brand sm:text-sm">
              </div>
              
              <div>
                <label for="roleDescription" class="block text-sm font-medium text-ink">Description</label>
                <textarea id="roleDescription" v-model="newRole.description" rows="3" class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 shadow-sm focus:border-brand focus:outline-none focus:ring-brand sm:text-sm"></textarea>
              </div>
              
              <div>
                <label class="block text-sm font-medium text-ink">Permissions</label>
                <div class="mt-2 space-y-2">
                  <div v-for="(permission, index) in newRole.permissions" :key="index" class="flex gap-2">
                    <input type="text" v-model="permission.scope" placeholder="Scope (e.g., branch)" class="block w-full rounded-md border border-line bg-white px-3 py-2 shadow-sm focus:border-brand focus:outline-none focus:ring-brand sm:text-sm">
                    <input type="text" v-model="permission.action" placeholder="Action (e.g., read, write)" class="block w-full rounded-md border border-line bg-white px-3 py-2 shadow-sm focus:border-brand focus:outline-none focus:ring-brand sm:text-sm">
                    <button type="button" @click="removePermission(index)" class="text-danger hover:text-danger-hover">Remove</button>
                  </div>
                  <button type="button" @click="addPermission" class="text-sm font-medium text-brand hover:underline">+ Add Permission</button>
                </div>
              </div>
              
              <div class="flex justify-end gap-3 pt-4">
                <button type="button" @click="showCreateRoleModal = false" class="rounded-md border border-line px-4 py-2 text-sm font-medium text-ink hover:bg-canvas">Cancel</button>
                <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-medium text-white hover:bg-brand-hover">Create Role</button>
              </div>
            </form>
          </div>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import Sidebar from '@/components/Sidebar.vue'
import { Menu, Bell, LogOut, Plus, X } from '@lucide/vue'
import api from '../api/client'

const router = useRouter()
const authStore = useAuthStore()
const drawerOpen = ref(false)
const showCreateRoleModal = ref(false)
const roles = ref([])
const newRole = ref({
  name: '',
  description: '',
  permissions: [
    { scope: '', action: '' }
  ]
})

const userName = computed(() => authStore.user?.name || 'Branch Administrator')
const firstName = computed(() => userName.value.split(' ')[0])
const initials = computed(() => userName.value.split(' ').slice(0, 2).map((part) => part[0]).join('').toUpperCase())
const roleLabel = computed(() => {
  const role = authStore.user?.roles?.[0] || 'branch administrator'
  return role.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
})

const closeDrawerOnDesktop = () => {
  if (window.innerWidth >= 1024) drawerOpen.value = false
}

const handleLogout = async () => {
  await authStore.logout()
  await router.replace('/login')
}

// Load roles from API
const loadRoles = async () => {
  try {
    const response = await api.get('/roles')
    roles.value = response.data
  } catch (error) {
    console.error('Failed to load roles:', error)
  }
}

onMounted(() => {
  window.addEventListener('resize', closeDrawerOnDesktop)
  // Load roles when component mounts
  loadRoles()
})

const addPermission = () => {
  newRole.value.permissions.push({ scope: '', action: '' })
}

const removePermission = (index) => {
  newRole.value.permissions.splice(index, 1)
}

// Create a new role via API
const createRole = async () => {
  try {
    const response = await api.post('/roles', newRole.value)
    // Add the new role to the list
    roles.value.push(response.data)
    showCreateRoleModal.value = false
    newRole.value = { name: '', description: '', permissions: [{ scope: '', action: '' }] }
  } catch (error) {
    console.error('Failed to create role:', error)
    // In a real app, you'd want to display this error to the user
  }
}

// Edit a role (stub for now - would need a separate edit modal in real implementation)
const editRole = (role) => {
  console.log('Editing role:', role)
  // In a real app, this would open an edit modal with current data
}

// Delete a role via API
const deleteRole = async (role) => {
  if (!confirm(`Are you sure you want to delete the role "${role.name}"?`)) {
    return
  }
  
  try {
    await api.delete(`/roles/${role.id}`)
    // Remove the role from the list
    roles.value = roles.value.filter(r => r.id !== role.id)
  } catch (error) {
    console.error('Failed to delete role:', error)
  }
}

// Add navigation to sidebar - this would need to be added to Sidebar.vue as well
</script>