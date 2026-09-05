<template>
  <div class="min-h-screen bg-canvas text-ink">
    <div v-if="drawerOpen" class="fixed inset-0 z-40 bg-ink/45 lg:hidden" aria-hidden="true" @click="drawerOpen = false"></div>

    <Sidebar v-model:drawer-open="drawerOpen" />

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
          <button type="button" class="inline-flex min-h-11 items-center justify-center gap-2 self-start rounded-md bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-hover sm:self-auto" @click="startCreate"><UserPlus :size="18" aria-hidden="true" />New organization</button>
        </section>

        <!-- Story 1.4: data-context banner — tells the user whose branch they are viewing -->
        <div v-if="store.scope" role="status" class="mb-6 flex items-center gap-3 rounded-md border px-4 py-3 text-sm font-medium" :class="store.isChurchWide ? 'border-info/40 bg-info-soft text-info' : 'border-brand/40 bg-brand-soft text-brand'">
          <component :is="store.isChurchWide ? Network : MapPin" :size="18" aria-hidden="true" />
          <span>{{ store.scopeLabel }}</span>
        </div>

        <div v-if="error" role="alert" class="mb-6 flex items-start justify-between gap-3 rounded-md border border-danger/40 bg-danger-soft px-4 py-3 text-sm font-medium text-danger-ink">
          <span>{{ error }}</span>
          <button type="button" class="shrink-0 text-xs font-semibold underline hover:no-underline" @click="error = null">Dismiss</button>
        </div>

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
              <OrganizationTree v-else :organizations="treeRoots" :on-edit="startEdit" :on-delete="confirmDelete" />
            </div>
            <button type="button" class="flex min-h-11 w-full items-center justify-center gap-2 border-t border-line text-sm font-semibold text-brand hover:bg-canvas" @click="loadOrganizations"><RefreshCw :size="16" aria-hidden="true" />Reload structure</button>
          </section>

          <section class="border border-line bg-white shadow-sm" :aria-labelledby="formHeadingId">
            <div class="flex items-center justify-between border-b border-line px-5 py-4">
              <div><h2 :id="formHeadingId" class="font-semibold">{{ editing ? 'Edit organization' : 'New organization' }}</h2><p class="mt-0.5 text-xs text-muted">{{ editing ? `Editing ${editing.name}` : 'Add a unit to the structure' }}</p></div>
              <button v-if="editing" type="button" class="text-xs font-semibold text-brand hover:underline" @click="cancelEdit">Cancel</button>
            </div>
            <form @submit.prevent="handleSubmit" class="space-y-4 px-5 py-4">
              <div v-if="success" role="status" class="rounded-md border border-success/30 bg-success-soft px-3 py-2 text-sm font-medium text-success">{{ success }}</div>

              <div>
                <label for="org-name" class="block text-sm font-medium">Name *</label>
                <input id="org-name" ref="nameInput" v-model="form.name" type="text" required placeholder="e.g. Eastside Branch" class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/25">
              </div>

              <div>
                <label for="org-type" class="block text-sm font-medium">Type *</label>
                <select id="org-type" v-model="form.type" required class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/25">
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
                <input id="org-identifier" v-model="form.identifier" type="text" required placeholder="e.g. EA-014" class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/25">
              </div>

              <div>
                <label for="org-parent" class="block text-sm font-medium">Parent organization</label>
                <select id="org-parent" v-model="form.parent_id" class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/25">
                  <option :value="null">None (top-level)</option>
                  <option v-for="org in parentOptions" :key="org.id" :value="org.id">{{ org.name }} ({{ org.type }})</option>
                </select>
              </div>

              <div>
                <label for="org-description" class="block text-sm font-medium">Description</label>
                <textarea id="org-description" v-model="form.description" rows="3" placeholder="Optional notes about this unit" class="mt-1 block w-full rounded-md border border-line bg-white px-3 py-2 text-sm shadow-sm focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/25"></textarea>
              </div>

              <fieldset class="space-y-3 rounded-md border border-line p-4">
                <legend class="px-1 text-sm font-medium">Location</legend>
                <input v-model="form.location.address_line1" type="text" placeholder="Address line 1" class="block w-full rounded-md border border-line bg-white px-3 py-2 text-sm" />
                <input v-model="form.location.address_line2" type="text" placeholder="Address line 2" class="block w-full rounded-md border border-line bg-white px-3 py-2 text-sm" />
                <div class="grid gap-3 sm:grid-cols-2">
                  <input v-model="form.location.city" type="text" placeholder="City" class="rounded-md border border-line bg-white px-3 py-2 text-sm" />
                  <input v-model="form.location.state" type="text" placeholder="State / region" class="rounded-md border border-line bg-white px-3 py-2 text-sm" />
                  <input v-model="form.location.postal_code" type="text" placeholder="Postal code" class="rounded-md border border-line bg-white px-3 py-2 text-sm" />
                  <input v-model="form.location.country" type="text" placeholder="Country" class="rounded-md border border-line bg-white px-3 py-2 text-sm" />
                </div>
              </fieldset>

              <fieldset class="space-y-3 rounded-md border border-line p-4">
                <legend class="px-1 text-sm font-medium">Primary contact</legend>
                <input v-model="form.primary_contact.name" type="text" placeholder="Contact name" class="block w-full rounded-md border border-line bg-white px-3 py-2 text-sm" />
                <input v-model="form.primary_contact.email" type="email" placeholder="Contact email" class="block w-full rounded-md border border-line bg-white px-3 py-2 text-sm" />
                <input v-model="form.primary_contact.phone" type="text" placeholder="Contact phone" class="block w-full rounded-md border border-line bg-white px-3 py-2 text-sm" />
              </fieldset>

              <button type="submit" :disabled="saving" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-md bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-hover disabled:cursor-not-allowed disabled:opacity-60">
                {{ saving ? (editing ? 'Saving…' : 'Creating…') : editing ? 'Save changes' : 'Create organization' }}
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
import { extractApiError } from '../api/client';
import OrganizationTree from '@/components/OrganizationTree.vue';
import Sidebar from '@/components/Sidebar.vue';

const router = useRouter();
const authStore = useAuthStore();
const store = useOrganizationStore();
const drawerOpen = ref(false);
const saving = ref(false);
const success = ref(null);
const error = ref(null);
const loadedAt = ref('');
const nameInput = ref(null);

// Edit state: null when the form is in "create" mode.
const editing = ref(null);
const formHeadingId = computed(() => (editing.value ? 'edit-heading' : 'create-heading'));

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

const emptyLocation = () => ({
  address_line1: '',
  address_line2: '',
  city: '',
  state: '',
  postal_code: '',
  country: '',
});

const emptyPrimaryContact = () => ({
  name: '',
  email: '',
  phone: '',
});

const form = reactive({
  name: '',
  type: 'branch',
  identifier: '',
  parent_id: null,
  description: '',
  location: emptyLocation(),
  primary_contact: emptyPrimaryContact(),
});

// Parent dropdown excludes the organization being edited (it can't be its own parent).
const parentOptions = computed(() => all.value.filter((org) => !editing.value || org.id !== editing.value.id));

function clearFeedback() {
  success.value = null;
  error.value = null;
}

async function loadOrganizations() {
  store.error = null;
  try {
    await store.fetchOrganizations();
    loadedAt.value = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  } catch (err) {
    console.error('Error loading organizations:', err);
    error.value = extractApiError(err, 'Failed to load organizations');
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

function pruneEmptyObject(obj) {
  const cleaned = Object.fromEntries(
    Object.entries(obj).filter(([, value]) => value !== null && value !== undefined && String(value).trim() !== ''),
  );

  return Object.keys(cleaned).length ? cleaned : null;
}

function resetForm() {
  Object.assign(form, {
    name: '',
    type: 'branch',
    identifier: '',
    parent_id: null,
    description: '',
    location: emptyLocation(),
    primary_contact: emptyPrimaryContact(),
  });
}

function startCreate() {
  editing.value = null;
  clearFeedback();
  resetForm();
  focusNameField();
}

function startEdit(org) {
  editing.value = org;
  clearFeedback();
  Object.assign(form, {
    name: org.name || '',
    type: org.type || 'branch',
    identifier: org.identifier || '',
    parent_id: org.parent_id ?? null,
    description: org.description || '',
    location: { ...emptyLocation(), ...(org.location || {}) },
    primary_contact: { ...emptyPrimaryContact(), ...(org.primary_contact || {}) },
  });
  focusNameField();
}

function cancelEdit() {
  editing.value = null;
  clearFeedback();
  resetForm();
}

async function handleSubmit() {
  saving.value = true;
  clearFeedback();
  const payload = {
    ...form,
    parent_id: form.parent_id || null,
    location: pruneEmptyObject(form.location),
    primary_contact: pruneEmptyObject(form.primary_contact),
  };
  try {
    if (editing.value) {
      await store.updateOrganization(editing.value.id, payload);
      success.value = 'Organization updated successfully';
      editing.value = null;
      resetForm();
    } else {
      await store.createOrganization(payload);
      success.value = 'Organization created successfully';
      resetForm();
    }
  } catch (err) {
    console.error('Error saving organization:', err);
    error.value = extractApiError(err, editing.value ? 'Failed to update organization' : 'Failed to create organization');
  } finally {
    saving.value = false;
  }
}

function confirmDelete(org) {
  if (!window.confirm(`Delete "${org.name}"? This cannot be undone.`)) return;
  deleteOrganization(org);
}

async function deleteOrganization(org) {
  saving.value = true;
  clearFeedback();
  try {
    await store.deleteOrganization(org.id);
    success.value = `Deleted ${org.name}`;
    if (editing.value?.id === org.id) cancelEdit();
  } catch (err) {
    console.error('Error deleting organization:', err);
    error.value = extractApiError(err, 'Failed to delete organization');
  } finally {
    saving.value = false;
  }
}

onMounted(() => loadOrganizations());
</script>
