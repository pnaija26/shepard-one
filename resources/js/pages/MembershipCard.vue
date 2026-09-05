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
            <p class="truncate text-sm font-semibold text-ink">Membership card</p>
            <p class="truncate text-xs text-muted">Your verifiable digital church ID</p>
          </div>
          <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="cardStore.loading" @click="refreshCard">
            Refresh
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Member services</p>
          <h1 class="font-serif text-3xl font-bold">Digital membership card</h1>
          <p class="mt-1 text-sm text-muted">Show this card for identity checks and approved QR-enabled services</p>
        </section>

        <p v-if="cardStore.error" class="mb-4 rounded-md border border-danger/30 bg-danger/5 px-4 py-3 text-sm text-danger" role="alert">
          {{ cardStore.error }}
        </p>

        <div v-if="cardStore.loading" class="text-sm text-muted">Loading card…</div>

        <div v-else-if="cardStore.card" class="mx-auto max-w-md">
          <article class="overflow-hidden rounded-xl border border-line bg-white shadow-sm">
            <div class="bg-brand px-6 py-4 text-white">
              <p class="text-xs uppercase tracking-wider text-white/70">ShepardOne</p>
              <h2 class="font-serif text-xl font-bold">Membership card</h2>
            </div>

            <div class="p-6">
              <div class="mb-4 flex items-center gap-4">
                <div class="grid size-16 place-items-center overflow-hidden rounded-full border border-line bg-canvas text-lg font-semibold text-brand">
                  <img v-if="cardStore.card.photo_path" :src="cardStore.card.photo_path" alt="" class="size-full object-cover" />
                  <span v-else>{{ initials }}</span>
                </div>
                <div>
                  <p class="text-lg font-semibold">{{ cardStore.card.full_name }}</p>
                  <p class="font-mono text-xs text-muted">{{ cardStore.card.membership_id }}</p>
                </div>
              </div>

              <dl class="mb-6 grid grid-cols-2 gap-3 text-sm">
                <div>
                  <dt class="text-xs text-muted">Branch</dt>
                  <dd class="font-medium">{{ cardStore.card.branch?.name || '—' }}</dd>
                </div>
                <div>
                  <dt class="text-xs text-muted">Status</dt>
                  <dd class="font-medium capitalize">{{ cardStore.card.status }}</dd>
                </div>
              </dl>

              <div class="flex flex-col items-center rounded-md border border-line bg-canvas/40 p-4">
                <canvas ref="qrCanvas" class="size-44" aria-label="Membership card QR code"></canvas>
                <p class="mt-3 text-center text-xs text-muted">
                  QR refreshes automatically · expires {{ formatExpiry(cardStore.card.qr?.expires_at) }}
                </p>
              </div>
            </div>
          </article>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import QRCode from 'qrcode'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useMembershipCardStore } from '../stores/membershipCard'

const cardStore = useMembershipCardStore()
const drawerOpen = ref(false)
const qrCanvas = ref(null)
let refreshTimer = null

const initials = computed(() => {
  const name = cardStore.card?.full_name || ''
  return name.split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase()
})

const renderQr = async () => {
  const payload = cardStore.card?.qr?.payload
  if (!payload || !qrCanvas.value) return
  await QRCode.toCanvas(qrCanvas.value, payload, {
    width: 176,
    margin: 1,
    errorCorrectionLevel: 'M',
  })
}

const refreshCard = async () => {
  await cardStore.fetchCard()
  await nextTick()
  await renderQr()
}

const formatExpiry = (value) => {
  if (!value) return 'soon'
  return new Date(value).toLocaleTimeString()
}

watch(() => cardStore.card?.qr?.payload, async () => {
  await nextTick()
  await renderQr()
})

onMounted(async () => {
  await refreshCard()
  refreshTimer = window.setInterval(refreshCard, 4 * 60 * 1000)
})

onUnmounted(() => {
  if (refreshTimer) window.clearInterval(refreshTimer)
})
</script>
