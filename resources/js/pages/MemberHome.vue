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
            <p class="truncate text-sm font-semibold">{{ greeting }}</p>
            <p class="truncate text-xs text-muted">{{ branchLabel }}</p>
          </div>
          <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="syncBadgeClass" role="status">{{ syncLabel }}</span>
        </div>
      </header>

      <main class="px-4 py-6 pb-24 sm:px-6 lg:px-8 lg:py-8 lg:pb-8">
        <section v-if="store.loading && !store.dashboard" class="rounded-md border border-line bg-white p-8 text-center" role="status" aria-live="polite">
          <p class="text-sm text-muted">Loading your dashboard…</p>
        </section>

        <section v-else-if="store.error" class="mb-6 rounded-md border border-danger/35 bg-danger-soft p-4" role="alert">
          <p class="text-sm font-semibold text-danger-ink">{{ store.sessionExpired ? 'Session expired' : store.offline ? 'Offline' : 'Something went wrong' }}</p>
          <p class="mt-1 text-sm text-danger-strong">{{ store.error }}</p>
          <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" class="rounded-md bg-brand px-3 py-2 text-xs font-semibold text-white" @click="recover('refresh')">Try again</button>
            <button v-if="store.sessionExpired" type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" @click="recover('sign_in')">Sign in again</button>
            <button v-if="store.offline" type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" @click="recover('go_online')">Reconnect</button>
          </div>
        </section>

        <template v-if="store.dashboard">
          <section class="mb-6 border-b border-line pb-6" aria-labelledby="home-heading">
            <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Member home</p>
            <h1 id="home-heading" class="font-serif text-3xl font-bold">{{ displayName }}</h1>
            <p class="mt-1 text-sm text-muted">Your church life, requests, and schedule in one place.</p>
          </section>

          <section v-if="store.quickActions.length" class="mb-6" aria-labelledby="quick-actions-heading">
            <h2 id="quick-actions-heading" class="mb-3 text-sm font-semibold">Quick actions</h2>
            <div class="flex gap-2 overflow-x-auto pb-1">
              <a v-for="action in store.quickActions" :key="action.key" :href="action.path" class="inline-flex min-h-11 shrink-0 items-center rounded-md border border-line bg-white px-4 text-sm font-semibold shadow-sm hover:bg-canvas">
                {{ action.label }}
              </a>
            </div>
          </section>

          <div class="grid gap-4 lg:grid-cols-2">
            <section
              v-for="section in store.visibleSections"
              :key="section.key"
              class="rounded-md border border-line bg-white p-4 shadow-sm"
              :aria-label="section.label"
            >
              <div class="mb-3 flex items-start justify-between gap-3">
                <div>
                  <h2 class="font-semibold">{{ section.label }}</h2>
                  <p v-if="sectionSummary(section)" class="mt-1 text-sm text-muted">{{ sectionSummary(section) }}</p>
                </div>
                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="stateClass(section.state)" :aria-label="`Section state: ${section.state}`">{{ stateLabel(section.state) }}</span>
              </div>

              <ul v-if="section.highlights?.length" class="space-y-2 text-sm">
                <li v-for="(item, index) in section.highlights.slice(0, 4)" :key="`${section.key}-${index}`" class="rounded-md border border-line/70 px-3 py-2">
                  <p class="font-medium">{{ highlightTitle(item, section.key) }}</p>
                  <p v-if="highlightDetail(item, section.key)" class="text-xs text-muted">{{ highlightDetail(item, section.key) }}</p>
                </li>
              </ul>

              <p v-else-if="section.state === 'empty'" class="text-sm text-muted" role="status">Nothing here yet.</p>
              <p v-else-if="section.state === 'unavailable'" class="text-sm text-muted" role="status">{{ section.summary?.message || 'Unavailable for your account.' }}</p>

              <div v-if="section.actions?.length" class="mt-4 flex flex-wrap gap-2">
                <a v-for="action in section.actions" :key="action.path" :href="action.path" class="rounded-md border border-line px-3 py-2 text-xs font-semibold hover:bg-canvas">{{ action.label }}</a>
              </div>
            </section>
          </div>
        </template>
      </main>

      <nav class="fixed inset-x-0 bottom-0 z-20 border-t border-line bg-white/95 px-2 py-2 backdrop-blur lg:hidden" aria-label="Member navigation">
        <div class="mx-auto grid max-w-lg grid-cols-5 gap-1 text-center text-[11px]">
          <a href="/home" class="rounded-md px-2 py-2 font-semibold text-brand" aria-current="page">Home</a>
          <a href="/my-roster-assignments" class="rounded-md px-2 py-2 text-muted hover:text-ink">Schedule</a>
          <a href="/membership-card" class="rounded-md px-2 py-2 text-muted hover:text-ink">Check-in</a>
          <a href="/notifications" class="rounded-md px-2 py-2 text-muted hover:text-ink">Inbox</a>
          <a href="/my-profile" class="rounded-md px-2 py-2 text-muted hover:text-ink">Profile</a>
        </div>
      </nav>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { Menu } from '@lucide/vue';
import Sidebar from '../components/Sidebar.vue';
import { useMemberDashboardStore } from '../stores/memberDashboard';
import { useHybridStore } from '../stores/hybrid';

const drawerOpen = ref(false);
const store = useMemberDashboardStore();
const hybrid = useHybridStore();

const profileSection = computed(() => store.sections.find((section) => section.key === 'profile'));

const displayName = computed(() => profileSection.value?.summary?.display_name || 'Member');
const branchLabel = computed(() => profileSection.value?.summary?.branch || 'Your church');
const greeting = computed(() => {
  const hour = new Date().getHours();
  if (hour < 12) return 'Good morning';
  if (hour < 17) return 'Good afternoon';
  return 'Good evening';
});

const syncLabel = computed(() => {
  if (!hybrid.sync.online) return 'Offline';
  if (hybrid.sync.pendingCount) return `${hybrid.sync.pendingCount} pending sync`;
  if (store.loading) return 'Refreshing';
  return 'Up to date';
});

const syncBadgeClass = computed(() => {
  if (!hybrid.sync.online) return 'bg-warning-soft text-warning-ink';
  if (hybrid.sync.pendingCount) return 'bg-accent/30 text-brand';
  return 'bg-success-soft text-success';
});

onMounted(async () => {
  hybrid.watchSync();
  try {
    await store.load();
  } catch {
    /* surfaced in store.error */
  }
});

function recover(action) {
  store.recover(action);
}

function stateClass(state) {
  if (state === 'ready') return 'bg-success-soft text-success';
  if (state === 'empty') return 'bg-canvas text-muted';
  if (state === 'unavailable') return 'bg-warning-soft text-warning-ink';
  return 'bg-canvas text-muted';
}

function stateLabel(state) {
  const labels = {
    ready: 'Ready',
    empty: 'Empty',
    unavailable: 'Unavailable',
    unauthorized: 'Unavailable',
    stale: 'Needs refresh',
  };
  return labels[state] || state;
}

function sectionSummary(section) {
  if (section.key === 'profile') {
    const parts = [section.summary?.membership_status, section.summary?.lifecycle_status].filter(Boolean);
    return parts.join(' · ');
  }
  if (section.key === 'messages' && section.summary?.unread_count != null) {
    return `${section.summary.unread_count} unread`;
  }
  if (section.key === 'family' && section.summary?.household_name) {
    return section.summary.household_name;
  }
  if (section.key === 'care' && section.summary?.message) {
    return section.summary.message;
  }
  return '';
}

function highlightTitle(item, key) {
  if (key === 'messages') return item.message;
  if (key === 'schedule') return item.title;
  if (key === 'groups') return item.name;
  if (key === 'teams' || key === 'assignments') return item.team || item.duty;
  if (key === 'attendance') return `${item.date} · ${item.status}`;
  if (key === 'welfare') return item.case_number;
  if (key === 'prayer') return item.reference;
  if (key === 'newsletters') return item.newsletter;
  return Object.values(item).find((value) => typeof value === 'string') || 'Item';
}

function highlightDetail(item, key) {
  if (key === 'schedule') return [item.date, item.time, item.venue].filter(Boolean).join(' · ');
  if (key === 'welfare') return item.status;
  if (key === 'prayer') return item.status;
  if (key === 'assignments') return item.date;
  if (key === 'groups') return item.role;
  return '';
}
</script>
