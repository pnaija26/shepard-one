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
            <p class="truncate text-sm font-semibold text-ink">My profile</p>
            <p class="truncate text-xs text-muted">Update your contact and personal details</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">My account</p>
          <h1 class="font-serif text-3xl font-bold">Profile settings</h1>
          <p class="mt-1 text-sm text-muted">Some changes apply immediately; others require officer approval</p>
        </section>

        <p v-if="profileStore.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger" role="alert">
          {{ profileStore.error }}
        </p>

        <div v-if="profileStore.lastChanges" class="mb-4 rounded-md border border-line bg-white p-4 text-sm">
          <p class="font-medium">Last update result</p>
          <p v-for="item in profileStore.lastChanges.applied" :key="'a-' + item.field" class="mt-1 text-success">
            {{ item.field }} applied immediately
          </p>
          <p v-for="item in profileStore.lastChanges.pending" :key="'p-' + item.field" class="mt-1 text-brand">
            {{ item.field }} submitted for approval
          </p>
          <p v-for="item in profileStore.lastChanges.rejected" :key="'r-' + item.field" class="mt-1 text-danger">
            {{ item.field }}: {{ item.message }}
          </p>
        </div>

        <div v-if="profileStore.loading" class="text-sm text-muted">Loading profile…</div>

        <form v-else-if="profileStore.profile" class="max-w-2xl space-y-4 rounded-md border border-line bg-white p-6" @submit.prevent="saveProfile">
          <div>
            <label class="text-xs font-medium text-muted">Full name</label>
            <p class="mt-1 text-sm font-medium">{{ profileStore.profile.full_name }}</p>
            <p class="text-xs text-muted">{{ profileStore.profile.membership_id }}</p>
          </div>

          <div>
            <label class="text-xs font-medium text-muted">Phone (immediate)</label>
            <input v-model="form.phone" type="text" class="mt-1 block w-full rounded-md border border-line px-3 py-2 text-sm" />
          </div>

          <div>
            <label class="text-xs font-medium text-muted">Email (requires approval)</label>
            <input v-model="form.email" type="email" class="mt-1 block w-full rounded-md border border-line px-3 py-2 text-sm" />
            <p v-if="pendingFor('email')" class="mt-1 text-xs text-brand">Pending approval: {{ pendingFor('email').proposed_value }}</p>
          </div>

          <div>
            <label class="text-xs font-medium text-muted">Preferred name (requires approval)</label>
            <input v-model="form.preferred_name" type="text" class="mt-1 block w-full rounded-md border border-line px-3 py-2 text-sm" />
          </div>

          <div>
            <label class="text-xs font-medium text-muted">Occupation (immediate)</label>
            <input v-model="form.occupation" type="text" class="mt-1 block w-full rounded-md border border-line px-3 py-2 text-sm" />
          </div>

          <div>
            <label class="text-xs font-medium text-muted">Address line 1 (immediate)</label>
            <input v-model="form.address_line1" type="text" class="mt-1 block w-full rounded-md border border-line px-3 py-2 text-sm" />
          </div>

          <div>
            <label class="text-xs font-medium text-muted">City</label>
            <input v-model="form.city" type="text" class="mt-1 block w-full rounded-md border border-line px-3 py-2 text-sm" />
          </div>

          <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-hover disabled:opacity-50" :disabled="profileStore.saving">
            {{ profileStore.saving ? 'Saving…' : 'Save changes' }}
          </button>
        </form>

        <section v-if="profileStore.profile?.notifications?.length" class="mt-8 max-w-2xl">
          <h2 class="mb-3 font-semibold">Recent notifications</h2>
          <ul class="space-y-2">
            <li v-for="note in profileStore.profile.notifications" :key="note.id" class="rounded-md border border-line bg-white px-4 py-3 text-sm">
              {{ note.message }}
            </li>
          </ul>
        </section>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useProfileStore } from '../stores/profile'

const profileStore = useProfileStore()
const drawerOpen = ref(false)

const form = reactive({
  phone: '',
  email: '',
  preferred_name: '',
  occupation: '',
  address_line1: '',
  city: '',
})

const pendingFor = (field) => profileStore.profile?.pending_changes?.find((row) => row.field === field)

const syncForm = () => {
  if (!profileStore.profile) return
  form.phone = profileStore.profile.phone || ''
  form.email = profileStore.profile.email || ''
  form.preferred_name = profileStore.profile.preferred_name || ''
  form.occupation = profileStore.profile.occupation || ''
  form.address_line1 = profileStore.profile.address_line1 || ''
  form.city = profileStore.profile.city || ''
}

const saveProfile = async () => {
  await profileStore.updateProfile({ ...form })
  syncForm()
}

onMounted(async () => {
  await profileStore.fetchProfile()
  syncForm()
})
</script>
