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
        <section class="mb-6 flex flex-col gap-4 border-b border-line pb-6 sm:flex-row sm:items-end sm:justify-between" aria-labelledby="dashboard-heading">
          <div><p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Thursday, 13 August</p><h1 id="dashboard-heading" class="font-serif text-3xl font-bold">Good morning, {{ firstName }}</h1><p class="mt-1 text-sm text-muted">Here is what needs attention across Central Assembly.</p></div>
          <button type="button" class="inline-flex min-h-11 items-center justify-center gap-2 self-start rounded-md bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-hover sm:self-auto"><UserPlus :size="18" aria-hidden="true" />Add member</button>
        </section>

        <section aria-labelledby="metrics-heading">
          <div class="mb-3 flex items-center justify-between"><h2 id="metrics-heading" class="text-sm font-semibold">Branch overview</h2><p class="mt-1 text-xs text-muted">Updated today at 09:42</p></div>
          <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <article v-for="metric in metrics" :key="metric.label" class="border border-line bg-white p-4 shadow-sm sm:p-5">
              <div class="flex items-start justify-between gap-3"><p class="text-xs font-medium text-muted sm:text-sm">{{ metric.label }}</p><component :is="metric.icon" :size="18" :class="metric.iconClass" aria-hidden="true" /></div>
              <p class="mt-3 font-serif text-2xl font-bold sm:text-3xl">{{ metric.value }}</p><p :class="['mt-1 text-xs', metric.detailClass]">{{ metric.detail }}</p>
            </article>
          </div>
        </section>

        <div class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(300px,0.75fr)]">
          <section class="border border-line bg-white shadow-sm" aria-labelledby="attention-heading">
            <div class="flex items-center justify-between border-b border-line px-4 py-4 sm:px-5"><div><h2 id="attention-heading" class="font-semibold">Needs attention</h2><p class="mt-0.5 text-xs text-muted">Pending work and branch exceptions</p></div><span class="rounded-full bg-warning-soft px-2.5 py-1 text-xs font-semibold text-warning-ink">7 open</span></div>
            <div class="divide-y divide-line">
              <a v-for="task in tasks" :key="task.title" href="#" class="group flex min-h-18 items-center gap-3 px-4 py-3 hover:bg-canvas/70 sm:px-5">
                <span :class="['grid size-9 shrink-0 place-items-center rounded-md', task.iconBackground]"><component :is="task.icon" :size="18" :class="task.iconClass" aria-hidden="true" /></span>
                <span class="min-w-0 flex-1"><span class="block text-sm font-semibold group-hover:text-brand">{{ task.title }}</span><span class="mt-0.5 block text-xs text-muted">{{ task.detail }}</span></span>
                <span :class="['hidden rounded-full px-2.5 py-1 text-xs font-medium sm:block', task.statusClass]">{{ task.status }}</span><ChevronRight :size="18" class="shrink-0 text-muted" aria-hidden="true" />
              </a>
            </div>
            <a href="#tasks" class="flex min-h-11 items-center justify-center border-t border-line text-sm font-semibold text-brand hover:bg-canvas">View all tasks</a>
          </section>

          <section class="border border-line bg-white shadow-sm" aria-labelledby="schedule-heading">
            <div class="border-b border-line px-5 py-4"><h2 id="schedule-heading" class="font-semibold">Coming up</h2><p class="mt-0.5 text-xs text-muted">Branch schedule</p></div>
            <ol class="divide-y divide-line px-5">
              <li v-for="event in schedule" :key="event.title" class="flex gap-4 py-4">
                <time class="w-11 shrink-0 text-center" :datetime="event.datetime"><span class="block text-[11px] font-semibold uppercase text-danger">{{ event.month }}</span><span class="font-serif text-xl font-bold">{{ event.day }}</span></time>
                <div class="min-w-0 border-l border-line pl-4"><p class="truncate text-sm font-semibold">{{ event.title }}</p><p class="mt-1 flex items-center gap-1.5 text-xs text-muted"><Clock3 :size="14" aria-hidden="true" />{{ event.time }}</p></div>
              </li>
            </ol>
            <a href="#schedule" class="flex min-h-11 items-center justify-center border-t border-line text-sm font-semibold text-brand hover:bg-canvas">Open schedule</a>
          </section>
        </div>

        <section class="mt-6 border border-line bg-white shadow-sm" aria-labelledby="activity-heading">
          <div class="flex items-center justify-between border-b border-line px-4 py-4 sm:px-5"><div><h2 id="activity-heading" class="font-semibold">Recent activity</h2><p class="mt-0.5 text-xs text-muted">Latest recorded branch updates</p></div><a href="/audit" class="min-h-11 px-2 text-sm font-semibold text-brand hover:underline">View audit log</a></div>
          <div class="overflow-x-auto"><table class="w-full min-w-[680px] text-left text-sm"><thead class="bg-canvas/75 text-xs font-medium text-muted"><tr><th class="px-5 py-3" scope="col">Activity</th><th class="px-5 py-3" scope="col">Area</th><th class="px-5 py-3" scope="col">Recorded by</th><th class="px-5 py-3" scope="col">Time</th><th class="px-5 py-3" scope="col">Status</th></tr></thead><tbody class="divide-y divide-line"><tr v-for="activity in activities" :key="activity.activity" class="hover:bg-canvas/50"><td class="px-5 py-3.5 font-medium">{{ activity.activity }}</td><td class="px-5 py-3.5 text-muted">{{ activity.area }}</td><td class="px-5 py-3.5 text-muted">{{ activity.actor }}</td><td class="px-5 py-3.5 text-muted">{{ activity.time }}</td><td class="px-5 py-3.5"><span class="inline-flex items-center gap-1.5 text-xs font-medium text-success"><CheckCircle2 :size="15" aria-hidden="true" />{{ activity.status }}</span></td></tr></tbody></table></div>
        </section>
      </main>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { BarChart3, Bell, CheckCircle2, ChevronDown, ChevronRight, CircleDollarSign, Clock3, FileCheck2, HeartHandshake, LayoutDashboard, LogOut, Mail, MapPin, Menu, MessageSquareText, Settings, ShieldCheck, UserPlus, Users, UserRoundCheck, X } from '@lucide/vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import Sidebar from '@/components/Sidebar.vue';

const router = useRouter();
const authStore = useAuthStore();
const drawerOpen = ref(false);
const userName = computed(() => authStore.user?.name || 'Branch Administrator');
const firstName = computed(() => userName.value.split(' ')[0]);
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

const metrics = [
  { label: 'Active members', value: '1,248', detail: '+18 this month', detailClass: 'text-success', icon: Users, iconClass: 'text-success' },
  { label: 'Sunday attendance', value: '842', detail: '67% of active members', detailClass: 'text-muted', icon: UserRoundCheck, iconClass: 'text-info' },
  { label: 'Open care cases', value: '12', detail: '3 awaiting review', detailClass: 'text-danger-ink', icon: HeartHandshake, iconClass: 'text-danger' },
  { label: 'Giving this month', value: '$28.4k', detail: '+6.2% from July', detailClass: 'text-success', icon: CircleDollarSign, iconClass: 'text-accent' }
];
const tasks = [
  { title: 'Review new member records', detail: '4 profiles need branch verification', status: 'Due today', statusClass: 'bg-warning-soft text-warning-ink', icon: FileCheck2, iconBackground: 'bg-success-soft', iconClass: 'text-success' },
  { title: 'Welfare requests awaiting approval', detail: 'Restricted workspace · 3 requests', status: 'High priority', statusClass: 'bg-danger-soft text-danger-ink', icon: ShieldCheck, iconBackground: 'bg-danger-soft', iconClass: 'text-danger' },
  { title: 'Send service team reminder', detail: 'Sunday morning service · 14 recipients', status: 'Scheduled', statusClass: 'bg-info-soft text-info-ink', icon: MessageSquareText, iconBackground: 'bg-info-soft', iconClass: 'text-info' }
];
const schedule = [
  { month: 'Aug', day: '14', title: 'Leadership prayer', time: '06:30 - 07:15', datetime: '2026-08-14T06:30' },
  { month: 'Aug', day: '16', title: 'Sunday morning service', time: '09:00 - 11:00', datetime: '2026-08-16T09:00' },
  { month: 'Aug', day: '18', title: 'New member orientation', time: '18:00 - 19:30', datetime: '2026-08-18T18:00' }
];
const activities = [
  { activity: '12 attendance records synced', area: 'Attendance', actor: 'Reception team', time: '8 min ago', status: 'Synchronized' },
  { activity: 'New member profile verified', area: 'People', actor: 'Ama Mensah', time: '24 min ago', status: 'Complete' },
  { activity: 'Service reminder scheduled', area: 'Communications', actor: userName.value, time: '1 hr ago', status: 'Scheduled' }
];
</script>