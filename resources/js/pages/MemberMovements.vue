<template>
  <div class="min-h-screen bg-canvas text-ink">
    <div v-if="drawerOpen" class="fixed inset-0 z-40 bg-ink/45 lg:hidden" aria-hidden="true" @click="drawerOpen = false"></div>

    <Sidebar :drawer-open="drawerOpen" />

    <div class="lg:pl-60">
      <header class="sticky top-0 z-30 border-b border-line bg-white/95 backdrop-blur">
        <div class="flex min-h-18 items-center gap-3 px-4 sm:px-6 lg:px-8">
          <button type="button" class="grid size-11 shrink-0 place-items-center rounded-md border border-line text-ink hover:bg-canvas lg:hidden" aria-label="Open navigation" aria-controls="primary-navigation" :aria-expanded="drawerOpen" @click="drawerOpen = true"><Menu :size="20" aria-hidden="true" /></button>
          <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold text-ink">Central Assembly</p><p class="truncate text-xs text-muted">{{ roleLabel }} workspace</p></div>
          <button type="button" class="relative grid size-11 place-items-center rounded-md text-muted hover:bg-canvas hover:text-ink" aria-label="Notifications"><Bell :size="20" aria-hidden="true" /><span class="absolute right-2.5 top-2.5 size-2 rounded-full bg-danger ring-2 ring-white"></span></button>
          <div class="hidden h-8 w-px bg-line sm:block"></div>
          <div class="hidden min-w-0 sm:block"><p class="max-w-40 truncate text-sm font-semibold">{{ userName }}</p><p class="max-w-40 truncate text-xs text-muted">{{ roleLabel }}</p></div>
          <div class="grid size-9 shrink-0 place-items-center rounded-full bg-brand text-xs font-semibold text-white" aria-hidden="true">{{ initials }}</div>
          <button type="button" class="grid size-11 place-items-center rounded-md text-muted hover:bg-canvas hover:text-ink" title="Sign out" aria-label="Sign out" @click="handleLogout"><LogOut :size="19" aria-hidden="true" /></button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 flex flex-col gap-4 border-b border-line pb-6 sm:flex-row sm:items-end sm:justify-between" aria-labelledby="movements-heading">
          <div>
            <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Organization</p>
            <h1 id="movements-heading" class="font-serif text-3xl font-bold">Member movements</h1>
            <p class="mt-1 text-sm text-muted">Control cross-branch identity movement — initiate, approve or reject transfers between branches.</p>
          </div>
          <button type="button" class="inline-flex min-h-11 items-center justify-center gap-2 self-start rounded-md bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-hover sm:self-auto" @click="scrollToForm"><ArrowRightLeft :size="18" aria-hidden="true" />New movement</button>
        </section>

        <!-- Story 1.4: data-context banner -->
        <div v-if="store.scope" role="status" class="mb-6 flex items-center gap-3 rounded-md border px-4 py-3 text-sm font-medium" :class="store.isChurchWide ? 'border-info/40 bg-info-soft text-info' : 'border-brand/40 bg-brand-soft text-brand'">
          <component :is="store.isChurchWide ? Network : MapPin" :size="18" aria-hidden="true" />
          <span>{{ store.scopeLabel }}</span>
        </div>

        <!-- Error banner -->
        <div v-if="error" role="alert" class="mb-6 flex items-start justify-between gap-3 rounded-md border border-danger/40 bg-danger-soft px-4 py-3 text-sm font-medium text-danger-ink">
          <span>{{ error }}</span>
          <button type="button" class="shrink-0 text-xs font-semibold underline hover:no-underline" @click="error = null">Dismiss</button>
        </div>

        <!-- Success banner -->
        <div v-if="success" role="status" class="mb-6 flex items-start justify-between gap-3 rounded-md border border-success/40 bg-success-soft px-4 py-3 text-sm font-medium text-success">
          <span>{{ success }}</span>
          <button type="button" class="shrink-0 text-xs font-semibold underline hover:no-underline" @click="success = null">Dismiss</button>
        </div>

        <!-- Metrics -->
        <section aria-labelledby="metrics-heading" class="mb-6">
          <h2 id="metrics-heading" class="sr-only">Movement metrics</h2>
          <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <article v-for="metric in metrics" :key="metric.label" class="border border-line bg-white p-4 shadow-sm sm:p-5">
              <div class="flex items-start justify-between gap-3"><p class="text-xs font-medium text-muted sm:text-sm">{{ metric.label }}</p><component :is="metric.icon" :size="18" :class="metric.iconClass" aria-hidden="true" /></div>
              <p class="mt-3 font-serif text-2xl font-bold sm:text-3xl">{{ metric.value }}</p>
              <p class="mt-1 text-xs text-muted">{{ metric.detail }}</p>
            </article>
          </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(320px,0.75fr)]">
          <!-- Movements list -->
          <section class="border border-line bg-white shadow-sm" aria-labelledby="list-heading">
            <div class="flex items-center justify-between border-b border-line px-4 py-4 sm:px-5">
              <div><h2 id="list-heading" class="font-semibold">Movements</h2><p class="mt-0.5 text-xs text-muted">All movements visible in your scope</p></div>
              <span class="rounded-full bg-brand-soft px-2.5 py-1 text-xs font-semibold text-brand">{{ store.allMovements.length }} total</span>
            </div>

            <!-- Filter tabs -->
            <div class="flex gap-1 border-b border-line px-4 pt-3 sm:px-5" role="tablist">
              <button v-for="tab in filterTabs" :key="tab.key" type="button" role="tab" :aria-selected="activeFilter === tab.key" class="rounded-t-md px-3 py-2 text-sm font-medium transition-colors" :class="activeFilter === tab.key ? 'border-b-2 border-brand bg-canvas text-ink' : 'text-muted hover:text-ink'" @click="activeFilter = tab.key">{{ tab.label }} ({{ tab.count }})</button>
            </div>

            <div class="px-4 py-3 sm:px-5">
              <div v-if="store.loading && !loadedOnce" class="flex items-center gap-3 py-8 text-sm text-muted"><LoaderCircle :size="18" class="animate-spin" aria-hidden="true" />Loading movements…</div>

              <p v-else-if="filtered.length === 0" class="py-8 text-center text-sm text-muted">No {{ activeFilter }} movements in your scope.</p>

              <ul v-else class="divide-y divide-line">
                <li v-for="m in filtered" :key="m.id" class="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                  <!-- Left: person + route -->
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold">{{ m.person?.name || `Person #${m.person_id}` }}</p>
                    <p class="mt-0.5 flex items-center gap-1.5 text-xs text-muted">
                      <span>{{ branchName(m.source_branch_id) }}</span>
                      <ArrowRight :size="13" aria-hidden="true" />
                      <span class="font-medium">{{ branchName(m.destination_branch_id) }}</span>
                    </p>
                    <p class="mt-0.5 text-xs text-muted">Effective {{ formatDate(m.effective_date) }} · Initiated by {{ m.initiator?.name || '—' }}</p>
                  </div>

                  <!-- Right: status + actions -->
                  <div class="flex shrink-0 items-center gap-2 sm:flex-col sm:items-end">
                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusClass(m.status)">{{ m.status }}</span>

                    <!-- Pending: approve / reject -->
                    <div v-if="m.status === 'pending'" class="flex gap-1.5">
                      <button type="button" class="rounded-md border border-success/40 bg-success-soft px-2.5 py-1 text-xs font-semibold text-success hover:bg-success/20" @click="approve(m)">Approve</button>
                      <button type="button" class="rounded-md border border-danger/40 bg-danger-soft px-2.5 py-1 text-xs font-semibold text-danger-ink hover:bg-danger/20" @click="reject(m)">Reject</button>
                    </div>

                    <!-- Approved: applied or waiting -->
                    <p v-if="m.status === 'approved'" class="text-[11px] text-muted">
                      {{ m.applied_at ? `Applied ${formatDate(m.applied_at)}` : 'Waiting for effective date' }}
                    </p>

                    <!-- Rejected: show reason -->
                    <p v-if="m.status === 'rejected'" class="max-w-48 truncate text-[11px] text-muted" :title="m.decision_reason">{{ m.decision_reason || 'Rejected' }}</p>
                  </div>
                </li>
              </ul>
            </div>

            <button type="button" class="flex min-h-11 w-full items-center justify-center gap-2 border-t border-line text-sm font-semibold text-brand hover:bg-canvas" @click="loadMovements"><RefreshCw :size="16" aria-hidden="true" />Reload</button>
          </section>

          <!-- Initiate form -->
          <section ref="formSection" class="border border-line bg-white shadow-sm" aria-labelledby="initiate-heading">
            <div class="flex items-center justify-between border-b border-line px-5 py-4">
              <div><h2 id="initiate-heading" class="font-semibold">Initiate movement</h2><p class="mt-0.5 text-xs text-muted">Transfer a person to another branch</p></div>
            </div>

            <form @submit.prevent="handleSubmit" class="space-y-4 px-5 py-4">
              <div v-if="formError" role="alert" class="rounded-md border border-danger/40 bg-danger-soft px-3 py-2 text-sm font-medium text-danger-ink">{{ formError }}</div>

              <!-- Person picker -->
              <div>
                <label for="mv-person" class="block text-sm font-medium">Person *</label>
                <select id="mv-person" v-model="form.person_id" required :disabled="peopleLoading" class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/25">
                  <option value="" disabled>{{ peopleLoading ? 'Loading…' : 'Select a person' }}</option>
                  <option v-for="p in store.people" :key="p.id" :value="p.id">{{ p.name }} ({{ branchName(p.branch_id) || 'Unassigned' }})</option>
                </select>
              </div>

              <!-- Destination branch -->
              <div>
                <label for="mv-destination" class="block text-sm font-medium">Destination branch *</label>
                <select id="mv-destination" v-model="form.destination_branch_id" required class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/25">
                  <option value="" disabled>Select destination branch</option>
                  <option v-for="b in branchOptions" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
              </div>

              <!-- Effective date -->
              <div>
                <label for="mv-date" class="block text-sm font-medium">Effective date *</label>
                <input id="mv-date" v-model="form.effective_date" type="date" required :min="todayStr" class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/25">
                <p class="mt-1 text-xs text-muted">The association changes on this date. Past dates are rejected.</p>
              </div>

              <!-- Reason -->
              <div>
                <label for="mv-reason" class="block text-sm font-medium">Reason *</label>
                <textarea id="mv-reason" v-model="form.reason" rows="3" required placeholder="e.g. Relocating to Eastside for ministry assignment" class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/25"></textarea>
              </div>

              <button type="submit" :disabled="saving || peopleLoading" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-md bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-hover disabled:cursor-not-allowed disabled:opacity-60">
                {{ saving ? 'Submitting…' : 'Initiate movement' }}
              </button>
            </form>
          </section>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { ArrowRight, ArrowRightLeft, Bell, Clock3, LoaderCircle, LogOut, MapPin, Menu, Network, RefreshCw, UserRoundCheck, XCircle } from '@lucide/vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useMovementStore } from '../stores/movement';
import { useOrganizationStore } from '../stores/organization';
import { extractApiError } from '../api/client';
import Sidebar from '@/components/Sidebar.vue';

const router = useRouter();
const authStore = useAuthStore();
const store = useMovementStore();
const orgStore = useOrganizationStore();

const drawerOpen = ref(false);
const saving = ref(false);
const success = ref(null);
const error = ref(null);
const formError = ref(null);
const activeFilter = ref('all');
const loadedOnce = ref(false);
const peopleLoading = ref(false);
const formSection = ref(null);

// --- Header helpers (same pattern as OrganizationManagement) ---
const userName = computed(() => authStore.user?.name || 'Branch Administrator');
const initials = computed(() => userName.value.split(' ').slice(0, 2).map((part) => part[0]).join('').toUpperCase());
const roleLabel = computed(() => {
  const role = authStore.user?.roles?.[0] || 'branch administrator';
  return role.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
});

const closeDrawerOnDesktop = () => { if (window.innerWidth >= 1024) drawerOpen.value = false; };
onMounted(() => window.addEventListener('resize', closeDrawerOnDesktop));
onBeforeUnmount(() => window.removeEventListener('resize', closeDrawerOnDesktop));

// --- Form state ---
const form = reactive({ person_id: '', destination_branch_id: '', effective_date: '', reason: '' });
const todayStr = new Date().toISOString().slice(0, 10);

// Destination options: only branch-type organizations.
const branchOptions = computed(() => orgStore.allOrganizations.filter(o => o.type === 'branch'));

function branchName(id) {
  if (!id) return '';
  const org = orgStore.allOrganizations.find(o => o.id === id);
  return org?.name || `Branch #${id}`;
}

// --- Filters ---
const filterTabs = computed(() => [
  { key: 'all', label: 'All', count: store.allMovements.length },
  { key: 'pending', label: 'Pending', count: store.pendingMovements.length },
  { key: 'approved', label: 'Approved', count: store.approvedMovements.length },
  { key: 'rejected', label: 'Rejected', count: store.rejectedMovements.length }
]);

const filtered = computed(() => {
  const all = store.allMovements;
  switch (activeFilter.value) {
    case 'pending': return store.pendingMovements;
    case 'approved': return store.approvedMovements;
    case 'rejected': return store.rejectedMovements;
    default: return all;
  }
});

// --- Metrics ---
const metrics = computed(() => [
  { label: 'Total movements', value: store.allMovements.length, detail: 'in your scope', icon: ArrowRightLeft, iconClass: 'text-brand' },
  { label: 'Pending approval', value: store.pendingMovements.length, detail: 'awaiting decision', icon: Clock3, iconClass: 'text-accent' },
  { label: 'Approved', value: store.approvedMovements.length, detail: `${store.appliedMovements.length} applied`, icon: UserRoundCheck, iconClass: 'text-success' },
  { label: 'Rejected', value: store.rejectedMovements.length, detail: 'association unchanged', icon: XCircle, iconClass: 'text-danger' }
]);

// --- Status badge classes ---
function statusClass(status) {
  switch (status) {
    case 'pending': return 'bg-accent/15 text-accent';
    case 'approved': return 'bg-success-soft text-success';
    case 'rejected': return 'bg-danger-soft text-danger-ink';
    default: return 'bg-canvas text-muted';
  }
}

function formatDate(dateStr) {
  if (!dateStr) return '—';
  const d = new Date(dateStr);
  return d.toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' });
}

// --- Data loading ---
async function loadMovements() {
  store.error = null;
  try {
    await store.fetchMovements();
    loadedOnce.value = true;
  } catch (err) {
    console.error('Error loading movements:', err);
    error.value = extractApiError(err, 'Failed to load movements');
  }
}

async function loadPeople() {
  peopleLoading.value = true;
  try {
    await store.fetchPeople();
  } catch (err) {
    console.error('Error loading people:', err);
    formError.value = extractApiError(err, 'Failed to load people');
  } finally {
    peopleLoading.value = false;
  }
}

// --- Actions ---
function scrollToForm() {
  formSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function handleSubmit() {
  saving.value = true;
  formError.value = null;
  success.value = null;

  try {
    await store.createMovement({
      person_id: Number(form.person_id),
      destination_branch_id: Number(form.destination_branch_id),
      effective_date: form.effective_date,
      reason: form.reason.trim()
    });
    success.value = 'Movement initiated — pending approval.';
    Object.assign(form, { person_id: '', destination_branch_id: '', effective_date: '', reason: '' });
  } catch (err) {
    console.error('Error creating movement:', err);
    formError.value = extractApiError(err, 'Failed to initiate movement');
  } finally {
    saving.value = false;
  }
}

async function approve(movement) {
  if (!window.confirm(`Approve movement for ${movement.person?.name || 'this person'}? The association will change on the effective date.`)) return;
  try {
    await store.approveMovement(movement.id);
    success.value = `Approved movement #${movement.id}`;
  } catch (err) {
    console.error('Error approving:', err);
    error.value = extractApiError(err, 'Failed to approve movement');
  }
}

async function reject(movement) {
  const reason = window.prompt(`Reject movement for ${movement.person?.name || 'this person'}.\nEnter a reason (required):`);
  if (reason === null) return; // cancelled
  if (!reason.trim()) { error.value = 'A rejection reason is required.'; return; }
  try {
    await store.rejectMovement(movement.id, reason.trim());
    success.value = `Rejected movement #${movement.id}`;
  } catch (err) {
    console.error('Error rejecting:', err);
    error.value = extractApiError(err, 'Failed to reject movement');
  }
}

function handleLogout() {
  authStore.logout().then(() => router.replace('/login'));
}

// --- Init: load orgs (for branch names) + movements + people in parallel ---
onMounted(async () => {
  // Load organizations first so branch names resolve, then movements and people.
  if (!orgStore.allOrganizations.length) {
    try { await orgStore.fetchOrganizations(); } catch (e) { /* non-fatal */ }
  }
  loadMovements();
  loadPeople();
});
</script>
