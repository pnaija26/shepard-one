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
        <section class="mb-6 flex flex-col gap-4 border-b border-line pb-6 sm:flex-row sm:items-end sm:justify-between" aria-labelledby="org-heading">
          <div><p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Organization</p><h1 id="org-heading" class="font-serif text-3xl font-bold">Organization management</h1><p class="mt-1 text-sm text-muted">Manage the structure of Central Assembly — branches, campuses, ministries and teams.</p></div>
          <button type="button" class="inline-flex min-h-11 items-center justify-center gap-2 self-start rounded-md bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-hover sm:self-auto"><UserPlus :size="18" aria-hidden="true" />New organization</button>
        </section>

        <section aria-labelledby="structure-heading">
          <div class="mb-3 flex items-center justify-between"><h2 id="structure-heading" class="text-sm font-semibold">Structure overview</h2><p v-if="loadedAt" class="text-xs text-muted">Loaded today at {{ loadedAt }}</p></div>
          <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <article v-for="metric in metrics" :key="metric.label" class="border border-line bg-white p-4 shadow-sm sm:p-5">
              <div class="flex items-start justify-between gap-3"><p class="text-xs font-medium text-muted sm:text-sm">{{ metric.label }}</p><component :is="metric.icon" :size="18" :class="metric.iconClass" aria-hidden="true" /></div>
              <p class="mt-3 font-serif text-2xl font-bold sm:text-3xl">{{ metric.value }}</p><p class="mt-1 text-xs text-muted">{{ metric.detail }}</p>
            </article>
          </div>
        </section>

        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(300px,0.75fr)]">
          <section class="border border-line bg-white shadow-sm" aria-labelledby="tree-heading">
            <div class="flex items-center justify-between border-b border-line px-4 py-4 sm:px-5"><div><h2 id="tree-heading" class="font-semibold">Organization structure</h2><p class="mt-0.5 text-xs text-muted">Full hierarchy from headquarters to teams</p></div><span class="rounded-full bg-brand-soft px-2.5 py-1 text-xs font-semibold text-brand">{{ all.length }} units</span></div>
            <div class="px-4 py-4 sm:px-5">
              <div v-if="store.loading" class="flex items-center gap-3 py-6 text-sm text-muted"><LoaderCircle :size="18" class="animate-spin" aria-hidden="true" />Loading organizations…</div>
              <div v-else-if="store.error" role="alert" class="rounded-md border border-danger/40 bg-danger-soft px-4 py-3 text-sm font-medium text-danger-ink">{{ store.error }}</div>
              <OrganizationTree v-else :organizations="treeRoots" />
            </div>
            <button type="button" class="flex min-h-11 w-full items-center justify-center gap-2 border-t border-line text-sm font-semibold text-brand hover:bg-canvas"><RefreshCw :size="16" aria-hidden="true" />Reload structure</button>
          </section>

          <section class="border border-line bg-white shadow-sm" aria-labelledby="create-heading">
            <div class="border-b border-line px-5 py-4"><h2 id="create-heading" class="font-semibold">New organization</h2><p class="mt-0.5 text-xs text-muted">Add a unit to the structure</p></div>
            <form @submit.prevent="createOrganization" class="space-y-4 px-5 py-4">
              <div v-if="success" role="status" class="rounded-md border border-success/30 bg-success-soft px-3 py-2 text-sm font-medium text-success">{{ success }}</div>

              <div>
                <label for="org-name" class="block text-sm font-medium">Name *</label>
                <input id="org-name" ref="nameInput" v-model="newOrg.name" type="text" required placeholder="e.g. Eastside Branch" class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/25">
              </div>

              <div>
                <label for="org-type" class="block text-sm font-medium">Type *</label>
                <select id="org-type" v-model="newOrg.type" required class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/25">
                  <option value="headquarters">Headquarters</option>
                  <option value="branch">Branch</option>
                  <option value="campus">Campus</option>
                  <option value="location">Location</option>
                  <option value="ministry">Ministry</option>
                  <option value="department">Department</option>
                  <option value="team">Team</option>
                  <option value="group">Group</option>
                </select>
              </div>

              <div>
                <label for="org-identifier" class="block text-sm font-medium">Identifier *</label>
                <input id="org-identifier" v-model="newOrg.identifier" type="text" required placeholder="e.g. EA-014" class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/25">
              </div>

              <div>
                <label for="org-parent" class="block text-sm font-medium">Parent organization</label>
                <select id="org-parent" v-model="newOrg.parent_id" class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/25">
                  <option :value="null">None (top-level)</option>
                  <option v-for="org in all" :key="org.id" :value="org.id">{{ org.name }} ({{ org.type }})</option>
                </select>
              </div>

              <div>
                <label for="org-description" class="block text-sm font-medium">Description</label>
                <textarea id="org-description" v-model="newOrg.description" rows="3" placeholder="Optional notes about this unit" class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/25"></textarea>
              </div>

              <button type="submit" :disabled="creating" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-md bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-hover disabled:cursor-not-allowed disabled:opacity-60">
                {{ creating ? 'Creating…' : 'Create organization' }}
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
import { Bell, Building2, LoaderCircle, LogOut, MapPin, Menu, Network, RefreshCw, TreePine, UserPlus } from '@lucide/vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useOrganizationStore } from '../stores/organization';
import OrganizationTree from '@/components/OrganizationTree.vue';
import Sidebar from '@/components/Sidebar.vue';

const router = useRouter();
const authStore = useAuthStore();
const store = useOrganizationStore();
const drawerOpen = ref(false);
const creating = ref(false);
const success = ref(null);
const loadedAt = ref('');
const nameInput = ref(null);

const userName = computed(() => authStore.user?.name || 'Branch Administrator');
const initials = computed(() => userName.value.split(' ').slice(0, 2).map((part) => part[0]).join('').toUpperCase());
const roleLabel = computed(() => {
  const role = authStore.user?.roles?.[0] || 'branch administrator';
  return role.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
});

const closeDrawerOnDesktop = () => {
  if (window.innerWidth >= 1024) drawerOpen.value = false;
};
const handleLogout = async () => {
  await authStore.logout();
  await router.replace('/login');
};
onMounted(() => window.addEventListener('resize', closeDrawerOnDesktop));
onBeforeUnmount(() => window.removeEventListener('resize', closeDrawerOnDesktop));

const newOrg = reactive({ name: '', type: 'branch', identifier: '', parent_id: null, description: '' });

async function loadOrganizations() {
  store.error = null;
  try {
    await store.fetchOrganizations();
    loadedAt.value = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  } catch (err) {
    console.error('Error loading organizations:', err);
  }
}

const all = computed(() => store.allOrganizations);
const treeRoots = computed(() => store.tree);

function maxDepth(nodes, level = 1) {
  let deepest = level;
  for (const node of nodes) {
    if (node.children?.length) deepest = Math.max(deepest, maxDepth(node.children, level + 1));
  }
  return deepest;
}

const metrics = computed(() => {
  const total = all.value.length;
  const roots = treeRoots.value.length;
  const branches = all.value.filter((org) => org.type === 'branch').length;
  const depth = total ? maxDepth(treeRoots.value) : 0;
  const pct = (n) => (total ? Math.round((n / total) * 100) : 0);
  return [
    { label: 'Total organizations', value: total, detail: 'across the structure', icon: Building2, iconClass: 'text-brand' },
    { label: 'Top-level units', value: roots, detail: 'no parent organization', icon: Network, iconClass: 'text-info' },
    { label: 'Branches', value: branches, detail: `${pct(branches)}% of total`, icon: MapPin, iconClass: 'text-success' },
    { label: 'Deepest level', value: depth, detail: 'levels deep', icon: TreePine, iconClass: 'text-accent' }
  ];
});

function focusNameField() {
  nameInput.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  nameInput.value?.focus();
}

async function createOrganization() {
  creating.value = true;
  success.value = null;
  try {
    await store.createOrganization({ ...newOrg, parent_id: newOrg.parent_id || null });
    Object.assign(newOrg, { name: '', type: 'branch', identifier: '', parent_id: null, description: '' });
    success.value = 'Organization created successfully';
  } catch (err) {
    console.error('Error creating organization:', err);
  } finally {
    creating.value = false;
  }
}

onMounted(() => loadOrganizations());
</script>
