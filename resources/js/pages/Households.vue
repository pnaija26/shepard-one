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
            <p class="truncate text-sm font-semibold text-ink">Households</p>
            <p class="truncate text-xs text-muted">Group related members into families</p>
          </div>
          <button type="button" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-hover" @click="showCreate = true">
            New household
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Membership</p>
          <h1 class="font-serif text-3xl font-bold">Households</h1>
          <p class="mt-1 text-sm text-muted">Manage family relationships without duplicating member records</p>
        </section>

        <p v-if="householdsStore.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger" role="alert">
          {{ householdsStore.error }}
        </p>

        <div v-if="householdsStore.loading" class="text-sm text-muted">Loading households…</div>

        <div v-else-if="householdsStore.households.length === 0" class="rounded-md border border-line bg-white p-8 text-center text-sm text-muted">
          No households in your scope yet.
        </div>

        <div v-else class="grid gap-4 lg:grid-cols-2">
          <button
            v-for="household in householdsStore.households"
            :key="household.id"
            type="button"
            class="rounded-md border border-line bg-white p-4 text-left shadow-sm hover:border-brand/40"
            @click="openHousehold(household.id)"
          >
            <p class="font-semibold">{{ household.name }}</p>
            <p class="mt-1 text-xs text-muted">{{ household.branch?.name }} · {{ household.members?.length || 0 }} members</p>
          </button>
        </div>
      </main>
    </div>

    <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center bg-ink/45 p-4" @click.self="showCreate = false">
      <form class="w-full max-w-lg rounded-md border border-line bg-white p-6 shadow-lg" @submit.prevent="createHousehold">
        <h2 class="font-serif text-xl font-bold">Create household</h2>
        <div class="mt-4 space-y-3">
          <input v-model="form.name" required placeholder="Household name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
          <input v-model="form.branch_id" required type="number" placeholder="Branch ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
          <input v-model="form.head_member_id" required type="number" placeholder="Head member ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
          <input v-model="form.spouse_member_id" type="number" placeholder="Spouse member ID (optional)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button type="button" class="rounded-md border border-line px-4 py-2 text-sm" @click="showCreate = false">Cancel</button>
          <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="householdsStore.saving">Create</button>
        </div>
      </form>
    </div>

    <div v-if="selectedId" class="fixed inset-0 z-50 flex items-center justify-center bg-ink/45 p-4" @click.self="selectedId = null">
      <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-md border border-line bg-white p-6 shadow-lg">
        <div class="mb-4 flex items-start justify-between gap-4">
          <div>
            <h2 class="font-serif text-xl font-bold">{{ householdsStore.selectedHousehold?.name }}</h2>
            <p class="text-sm text-muted">{{ householdsStore.selectedHousehold?.branch?.name }}</p>
          </div>
          <button type="button" class="text-sm text-muted hover:text-ink" @click="selectedId = null">Close</button>
        </div>

        <ul v-if="householdsStore.selectedHousehold" class="mb-6 space-y-2">
          <li v-for="member in householdsStore.selectedHousehold.members" :key="member.member_id" class="rounded-md border border-line px-4 py-3 text-sm">
            <p class="font-medium">{{ member.full_name }} <span class="text-xs capitalize text-muted">({{ member.relationship_type }})</span></p>
            <p class="text-xs text-muted">{{ member.contact?.email || member.contact?.phone || 'No contact shown' }}</p>
          </li>
        </ul>

        <form class="space-y-3" @submit.prevent="saveSharedContact">
          <input v-model="sharedForm.shared_phone" placeholder="Shared phone" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
          <input v-model="sharedForm.shared_email" placeholder="Shared email" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
          <p v-if="householdsStore.overwriteRequired" class="text-xs text-warning">
            Updating shared contact may overwrite member-specific values. Submit again to confirm.
          </p>
          <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="householdsStore.saving">
            Update shared contact
          </button>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useHouseholdsStore } from '../stores/households'

const householdsStore = useHouseholdsStore()
const drawerOpen = ref(false)
const showCreate = ref(false)
const selectedId = ref(null)

const form = reactive({
  name: '',
  branch_id: '',
  head_member_id: '',
  spouse_member_id: '',
})

const sharedForm = reactive({
  shared_phone: '',
  shared_email: '',
})

const createHousehold = async () => {
  const members = [{ member_id: Number(form.head_member_id), relationship_type: 'head' }]
  if (form.spouse_member_id) {
    members.push({ member_id: Number(form.spouse_member_id), relationship_type: 'spouse' })
  }

  await householdsStore.createHousehold({
    name: form.name,
    branch_id: Number(form.branch_id),
    members,
  })
  showCreate.value = false
}

const openHousehold = async (id) => {
  selectedId.value = id
  await householdsStore.fetchHousehold(id)
  sharedForm.shared_phone = householdsStore.selectedHousehold?.shared_phone || ''
  sharedForm.shared_email = householdsStore.selectedHousehold?.shared_email || ''
  householdsStore.overwriteRequired = null
}

const saveSharedContact = async () => {
  if (!selectedId.value) return
  const result = await householdsStore.updateHousehold(selectedId.value, { ...sharedForm }, Boolean(householdsStore.overwriteRequired))
  if (result === null && householdsStore.overwriteRequired) {
    await householdsStore.updateHousehold(selectedId.value, { ...sharedForm }, true)
    householdsStore.overwriteRequired = null
  }
}

onMounted(async () => {
  await householdsStore.fetchHouseholds()
})
</script>
