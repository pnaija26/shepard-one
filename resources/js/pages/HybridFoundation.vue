<template>
  <div class="min-h-screen bg-canvas text-ink">
    <div v-if="drawerOpen" class="fixed inset-0 z-40 bg-ink/45 lg:hidden" aria-hidden="true" @click="drawerOpen = false"></div>
    <Sidebar v-model:drawer-open="drawerOpen" />

    <div class="lg:pl-60">
      <header class="sticky top-0 z-30 border-b border-line bg-white/95 backdrop-blur">
        <div class="flex min-h-18 items-center gap-3 px-4 sm:px-6 lg:px-8">
          <button type="button" class="grid size-11 shrink-0 place-items-center rounded-md border border-line text-ink hover:bg-canvas lg:hidden" aria-label="Open navigation" :aria-expanded="drawerOpen" @click="drawerOpen = true">
            <Menu :size="20" aria-hidden="true" />
          </button>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold">Hybrid application</p>
            <p class="truncate text-xs text-muted">Story 12.1 foundation</p>
          </div>
          <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="syncBadgeClass" role="status">{{ syncLabel }}</span>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6" aria-labelledby="hybrid-heading">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Secure Vue hybrid</p>
          <h1 id="hybrid-heading" class="font-serif text-3xl font-bold">Installable Android and iOS shell</h1>
          <p class="mt-2 max-w-2xl text-sm text-muted">Capacitor wraps the church-branded Vue app, calls versioned HTTPS APIs, stores device credentials privately, and communicates offline sync clearly.</p>
        </section>

        <div v-if="hybrid.error" class="mb-4 rounded-md border border-danger/35 bg-danger-soft p-4" role="alert">{{ hybrid.error }}</div>

        <div class="grid gap-6 xl:grid-cols-2">
          <section class="border border-line bg-white p-5 shadow-sm" aria-labelledby="runtime-heading">
            <h2 id="runtime-heading" class="font-semibold">Runtime</h2>
            <dl class="mt-4 space-y-3 text-sm">
              <div class="flex justify-between gap-4"><dt class="text-muted">Platform</dt><dd class="font-medium">{{ hybrid.platform }}</dd></div>
              <div class="flex justify-between gap-4"><dt class="text-muted">Native bridge</dt><dd class="font-medium">{{ hybrid.isNative ? 'Capacitor native' : 'Web / simulator' }}</dd></div>
              <div class="flex justify-between gap-4"><dt class="text-muted">Bridge</dt><dd class="font-medium">{{ foundation?.runtime?.bridge }} {{ foundation?.runtime?.bridge_version }}</dd></div>
              <div class="flex justify-between gap-4"><dt class="text-muted">API version</dt><dd class="font-medium">{{ foundation?.api?.version ?? '—' }}</dd></div>
              <div class="flex justify-between gap-4"><dt class="text-muted">App ID</dt><dd class="font-mono text-xs">{{ foundation?.runtime?.app_id ?? '—' }}</dd></div>
            </dl>
          </section>

          <section class="border border-line bg-white p-5 shadow-sm" aria-labelledby="sync-heading">
            <div class="flex items-center justify-between gap-3">
              <h2 id="sync-heading" class="font-semibold">Connectivity and sync</h2>
              <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold hover:bg-canvas" @click="hybrid.retrySync()">Retry pending</button>
            </div>
            <p class="mt-2 text-sm text-muted">{{ hybrid.sync.online ? 'Online' : 'Offline' }} · {{ hybrid.sync.pendingCount }} pending</p>
            <div class="mt-4 flex flex-wrap gap-2">
              <button type="button" class="rounded-md bg-brand px-3 py-2 text-xs font-semibold text-white" @click="queueDraft">Queue offline-tolerant draft</button>
              <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" @click="queueUnsupported">Try unsupported offline action</button>
            </div>
            <p v-if="actionMessage" class="mt-3 text-sm" :class="actionOk ? 'text-success' : 'text-danger-strong'" role="status">{{ actionMessage }}</p>
            <ul v-if="hybrid.sync.items.length" class="mt-4 divide-y divide-line border-t border-line text-sm">
              <li v-for="item in hybrid.sync.items.slice(-5)" :key="item.id" class="py-2">
                <p class="font-medium">{{ item.action }}</p>
                <p class="text-xs text-muted">{{ item.status }} — {{ item.message }}</p>
              </li>
            </ul>
          </section>

          <section class="border border-line bg-white p-5 shadow-sm xl:col-span-2" aria-labelledby="permissions-heading">
            <h2 id="permissions-heading" class="font-semibold">Device permissions</h2>
            <p class="mt-1 text-sm text-muted">Permissions are requested only after purpose is confirmed. Denial leaves a usable fallback.</p>
            <ul class="mt-4 grid gap-3 md:grid-cols-2">
              <li v-for="perm in hybrid.permissions" :key="perm.key" class="border border-line p-4">
                <p class="text-sm font-semibold capitalize">{{ perm.key }}</p>
                <p class="mt-1 text-xs text-muted">{{ perm.purpose }}</p>
                <p class="mt-1 text-xs">Fallback: {{ perm.fallback }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                  <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" @click="askPermission(perm.key, false)">Request without purpose</button>
                  <button type="button" class="rounded-md bg-brand px-3 py-2 text-xs font-semibold text-white" @click="askPermission(perm.key, true)">Confirm purpose and request</button>
                </div>
              </li>
            </ul>
            <p v-if="hybrid.lastPermissionResult" class="mt-4 text-sm" role="status">
              Last result: {{ hybrid.lastPermissionResult.status }}
              <span v-if="!hybrid.lastPermissionResult.granted"> — {{ hybrid.lastPermissionResult.fallback }}</span>
            </p>
          </section>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { Menu } from '@lucide/vue';
import Sidebar from '../components/Sidebar.vue';
import { useHybridStore } from '../stores/hybrid';

const drawerOpen = ref(false);
const hybrid = useHybridStore();
const actionMessage = ref('');
const actionOk = ref(true);

const foundation = computed(() => hybrid.foundation);

const syncLabel = computed(() => {
  if (!hybrid.sync.online) return 'Offline';
  if (hybrid.sync.pendingCount) return `${hybrid.sync.pendingCount} pending sync`;
  return 'Synced';
});

const syncBadgeClass = computed(() => {
  if (!hybrid.sync.online) return 'bg-warning-soft text-warning-ink';
  if (hybrid.sync.pendingCount) return 'bg-accent/30 text-brand';
  return 'bg-success-soft text-success';
});

onMounted(async () => {
  hybrid.watchSync();
  try {
    await hybrid.loadFoundation();
  } catch {
    /* surfaced via store.error */
  }
});

function queueDraft() {
  const result = hybrid.tryOfflineAction('feedback.draft', { note: 'Saved locally' });
  actionOk.value = result.accepted;
  actionMessage.value = result.message;
}

function queueUnsupported() {
  const result = hybrid.tryOfflineAction('welfare.submit', { amount: 1 });
  actionOk.value = result.accepted;
  actionMessage.value = result.message;
}

async function askPermission(key, confirmed) {
  await hybrid.requestDevicePermission(key, confirmed);
}
</script>
