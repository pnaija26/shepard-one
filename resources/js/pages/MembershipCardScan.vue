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
            <p class="truncate text-sm font-semibold text-ink">Verify membership card</p>
            <p class="truncate text-xs text-muted">Scan or paste a card QR reference</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Membership</p>
          <h1 class="font-serif text-3xl font-bold">Card verification</h1>
          <p class="mt-1 text-sm text-muted">Returns only purpose-appropriate member data</p>
        </section>

        <form class="max-w-xl space-y-4 rounded-md border border-line bg-white p-6" @submit.prevent="submitVerification">
          <div>
            <label class="text-xs font-medium text-muted">Verification purpose</label>
            <select v-model="purpose" required class="mt-1 block w-full rounded-md border border-line px-3 py-2 text-sm">
              <option v-for="item in cardStore.purposes" :key="item.key" :value="item.key">{{ item.label }}</option>
            </select>
          </div>

          <div>
            <label class="text-xs font-medium text-muted">QR reference</label>
            <textarea v-model="token" required rows="4" placeholder="Paste scanned QR payload" class="mt-1 block w-full rounded-md border border-line px-3 py-2 font-mono text-xs"></textarea>
          </div>

          <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="cardStore.saving">
            {{ cardStore.saving ? 'Verifying…' : 'Verify card' }}
          </button>
        </form>

        <p v-if="cardStore.error" class="mt-4 rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger">
          {{ cardStore.error }}
        </p>

        <div v-if="cardStore.verification" class="mt-6 max-w-xl rounded-md border border-success/30 bg-success/5 p-4 text-sm">
          <p class="font-semibold text-success">Verified for {{ cardStore.verification.purpose }}</p>
          <pre class="mt-3 overflow-x-auto rounded-md bg-white p-3 text-xs">{{ JSON.stringify(cardStore.verification.member, null, 2) }}</pre>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useMembershipCardStore } from '../stores/membershipCard'

const cardStore = useMembershipCardStore()
const drawerOpen = ref(false)
const token = ref('')
const purpose = ref('identity_check')

const submitVerification = async () => {
  await cardStore.verify(token.value.trim(), purpose.value)
}

onMounted(async () => {
  await cardStore.fetchPurposes()
  if (cardStore.purposes.length) {
    purpose.value = cardStore.purposes[0].key
  }
})
</script>
