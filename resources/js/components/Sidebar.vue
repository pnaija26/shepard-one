<template>
  <aside id="primary-navigation" :class="['fixed inset-y-0 left-0 z-50 flex w-60 flex-col bg-brand text-white transition-transform duration-200 lg:translate-x-0', drawerOpen ? 'translate-x-0' : '-translate-x-full']">
    <div class="flex h-18 items-center justify-between border-b border-white/10 px-5">
      <a href="/dashboard" class="flex items-center gap-3 rounded-md text-white" aria-label="ShepardOne dashboard">
        <span class="grid size-9 place-items-center rounded-md border border-white/20 bg-white/10 font-serif text-base font-bold">S1</span>
        <span class="text-lg font-semibold">ShepardOne</span>
      </a>
      <button class="grid size-11 place-items-center rounded-md text-white/75 hover:bg-white/10 hover:text-white lg:hidden" type="button" aria-label="Close navigation" @click="drawerOpen = false">
        <X :size="20" aria-hidden="true" />
      </button>
    </div>

    <div class="border-b border-white/10 px-4 py-4">
      <button type="button" class="flex min-h-12 w-full items-center gap-3 rounded-md border border-white/15 bg-white/5 px-3 text-left hover:bg-white/10">
        <MapPin :size="17" class="shrink-0 text-accent" aria-hidden="true" />
        <span class="min-w-0 flex-1"><span class="block text-[11px] text-white/55">Active branch</span><span class="block truncate text-sm font-medium">Central Assembly</span></span>
        <ChevronDown :size="16" class="text-white/55" aria-hidden="true" />
      </button>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-5" aria-label="Primary navigation">
      <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-white/45">Home</p>
      <a
        v-for="item in homeNavigation"
        :key="item.href"
        :href="item.href"
        :class="navigationClass(isActive(item.href))"
        :aria-current="isActive(item.href) ? 'page' : undefined"
      >
        <component :is="item.icon" :size="18" aria-hidden="true" />
        <span>{{ item.label }}</span>
      </a>

      <div v-for="group in navigationGroups" :key="group.id" class="mt-4">
        <button
          type="button"
          class="mb-1 flex min-h-11 w-full items-center gap-3 rounded-md px-3 text-left text-sm font-semibold text-white/85 transition-colors hover:bg-white/8"
          :aria-expanded="isGroupExpanded(group.id)"
          :aria-controls="`nav-group-${group.id}`"
          @click="toggleGroup(group.id)"
        >
          <component :is="group.icon" :size="18" class="shrink-0 text-white/70" aria-hidden="true" />
          <span class="flex-1">{{ group.label }}</span>
          <ChevronDown
            :size="16"
            class="shrink-0 text-white/55 transition-transform duration-200"
            :class="isGroupExpanded(group.id) ? '' : '-rotate-90'"
            aria-hidden="true"
          />
        </button>

        <div
          v-show="isGroupExpanded(group.id)"
          :id="`nav-group-${group.id}`"
          class="ml-2 border-l border-white/10 pl-2"
        >
          <a
            v-for="item in group.items"
            :key="item.href"
            :href="item.href"
            :class="navigationClass(isActive(item.href))"
            :aria-current="isActive(item.href) ? 'page' : undefined"
          >
            <component :is="item.icon" :size="16" class="shrink-0 opacity-80" aria-hidden="true" />
            <span>{{ item.label }}</span>
            <span v-if="item.count" class="ml-auto rounded-full bg-accent px-2 py-0.5 text-[11px] font-semibold text-brand">{{ item.count }}</span>
          </a>
        </div>
      </div>
    </nav>

    <div class="border-t border-white/10 p-3">
      <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-white/45">My account</p>
      <a
        v-for="item in accountNavigation"
        :key="item.href"
        :href="item.href"
        :class="navigationClass(isActive(item.href))"
        :aria-current="isActive(item.href) ? 'page' : undefined"
      >
        <component :is="item.icon" :size="18" aria-hidden="true" />
        <span>{{ item.label }}</span>
      </a>
    </div>
  </aside>
</template>

<script setup>
import { ref, watch, defineModel } from 'vue'
import { useRoute } from 'vue-router'
import {
  ArrowRightLeft,
  Bell,
  BookUser,
  CheckCircle2,
  ChevronDown,
  CircleDollarSign,
  Clock3,
  CreditCard,
  FileCheck2,
  FileText,
  HeartHandshake,
  LayoutDashboard,
  Mail,
  MapPin,
  MessageSquareText,
  Settings,
  ShieldCheck,
  TreePine,
  UserPlus,
  UserRoundCheck,
  Users,
  X,
} from '@lucide/vue'

const drawerOpen = defineModel('drawerOpen', { type: Boolean, default: false })

const route = useRoute()
const expandedGroups = ref(new Set(['dashboards', 'people']))

const navigationClass = (active) => [
  'mb-1 flex min-h-10 items-center gap-3 rounded-md px-3 text-sm font-medium transition-colors',
  active ? 'bg-white/12 text-white' : 'text-white/70 hover:bg-white/8 hover:text-white',
]

const homeNavigation = [
  { label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
  { label: 'Member home', href: '/home', icon: UserRoundCheck },
]

const navigationGroups = [
  {
    id: 'dashboards',
    label: 'Dashboards',
    icon: LayoutDashboard,
    items: [
      { label: 'HQ dashboard', href: '/hq-dashboard', icon: LayoutDashboard },
      { label: 'Branch dashboard', href: '/branch-dashboard', icon: LayoutDashboard },
      { label: 'Team dashboard', href: '/team-dashboard', icon: LayoutDashboard },
      { label: 'My assigned dashboard', href: '/my-composable-dashboard', icon: LayoutDashboard },
      { label: 'Dashboard composer', href: '/composable-dashboards', icon: LayoutDashboard },
    ],
  },
  {
    id: 'reports',
    label: 'Reports',
    icon: FileCheck2,
    items: [
      { label: 'Standard reports', href: '/standard-reports', icon: FileCheck2 },
      { label: 'Custom reports', href: '/custom-reports', icon: FileCheck2 },
      { label: 'Report schedules', href: '/report-schedules', icon: FileCheck2 },
    ],
  },
  {
    id: 'records',
    label: 'Records',
    icon: FileText,
    items: [
      { label: 'Global search', href: '/global-search', icon: FileText },
      { label: 'Church documents', href: '/church-documents', icon: FileText },
    ],
  },
  {
    id: 'people',
    label: 'People',
    icon: Users,
    items: [
      { label: 'Members', href: '/members', icon: Users },
      { label: 'Visitors', href: '/visitors', icon: UserPlus },
      { label: 'Households', href: '/households', icon: HeartHandshake },
      { label: 'Directory', href: '/directory', icon: BookUser },
      { label: 'Verify card', href: '/membership-card/scan', icon: CreditCard },
      { label: 'Onboarding', href: '/onboarding', icon: Clock3 },
      { label: 'Follow-ups', href: '/follow-ups', icon: CheckCircle2 },
    ],
  },
  {
    id: 'gatherings',
    label: 'Gatherings',
    icon: Clock3,
    items: [
      { label: 'Services', href: '/services', icon: Clock3 },
      { label: 'Events', href: '/events', icon: FileCheck2 },
      { label: 'Event scan', href: '/events/admission-scan', icon: CreditCard },
      { label: 'Attendance', href: '/attendance-capture', icon: UserRoundCheck },
      { label: 'Attendance alerts', href: '/attendance-exceptions', icon: Bell },
      { label: 'Feedback', href: '/feedback', icon: MessageSquareText },
      { label: 'Incidents', href: '/incidents', icon: ShieldCheck },
    ],
  },
  {
    id: 'teams',
    label: 'Teams & volunteers',
    icon: HeartHandshake,
    items: [
      { label: 'Service teams', href: '/service-teams', icon: HeartHandshake },
      { label: 'Team rosters', href: '/team-rosters', icon: HeartHandshake },
      { label: 'Team attendance', href: '/team-attendance', icon: HeartHandshake },
      { label: 'Team reports', href: '/team-reports', icon: HeartHandshake },
      { label: 'Report forms', href: '/team-report-forms', icon: HeartHandshake },
      { label: 'Volunteer profiles', href: '/volunteers', icon: Users },
      { label: 'My roster assignments', href: '/my-roster-assignments', icon: HeartHandshake },
      { label: 'My volunteer profile', href: '/my-volunteer-profile', icon: BookUser },
    ],
  },
  {
    id: 'groups',
    label: 'Groups & training',
    icon: BookUser,
    items: [
      { label: 'Groups', href: '/groups', icon: Users },
      { label: 'Training', href: '/training', icon: BookUser },
    ],
  },
  {
    id: 'care',
    label: 'Care & welfare',
    icon: ShieldCheck,
    items: [
      { label: 'Welfare', href: '/welfare', icon: ShieldCheck },
      { label: 'Pastoral care', href: '/care', icon: HeartHandshake },
      { label: 'Prayer', href: '/prayer', icon: HeartHandshake },
    ],
  },
  {
    id: 'operations',
    label: 'Operations',
    icon: CheckCircle2,
    items: [
      { label: 'Tasks', href: '/tasks', icon: CheckCircle2 },
      { label: 'Workflows', href: '/workflows', icon: CheckCircle2 },
      { label: 'Automation rules', href: '/automation-rules', icon: CheckCircle2 },
    ],
  },
  {
    id: 'communications',
    label: 'Communications',
    icon: Mail,
    items: [
      { label: 'Communications', href: '/communications', icon: Mail },
      { label: 'Inbox', href: '/notifications', icon: Mail },
      { label: 'Message templates', href: '/message-templates', icon: Mail },
      { label: 'Milestone greetings', href: '/milestone-greetings', icon: Mail },
      { label: 'Newsletters', href: '/newsletters', icon: Mail },
      { label: 'Community spaces', href: '/community-spaces', icon: Mail },
      { label: 'Church content', href: '/church-content', icon: Mail },
    ],
  },
  {
    id: 'finance',
    label: 'Finance & giving',
    icon: CircleDollarSign,
    items: [
      { label: 'Payment sources', href: '/payment-sources', icon: CircleDollarSign },
      { label: 'Contributions', href: '/contributions', icon: CircleDollarSign },
      { label: 'Giving reports', href: '/giving-reports', icon: CircleDollarSign },
      { label: 'My giving', href: '/my-giving', icon: CircleDollarSign },
    ],
  },
  {
    id: 'migration',
    label: 'Migration',
    icon: FileCheck2,
    items: [
      { label: 'Data migration', href: '/data-migrations', icon: FileCheck2 },
      { label: 'API platform', href: '/api-platform', icon: FileCheck2 },
      { label: 'Outbound webhooks', href: '/outbound-webhooks', icon: FileCheck2 },
      { label: 'External adapters', href: '/external-adapters', icon: FileCheck2 },
      { label: 'Operations monitoring', href: '/operations-monitoring', icon: FileCheck2 },
    ],
  },
  {
    id: 'organization',
    label: 'Organization',
    icon: TreePine,
    items: [
      { label: 'Organization structure', href: '/organizations', icon: TreePine },
      { label: 'Member movements', href: '/movements', icon: ArrowRightLeft },
      { label: 'Role management', href: '/roles', icon: ShieldCheck },
      { label: 'Configuration', href: '/config', icon: Settings },
      { label: 'Audit log', href: '/audit', icon: FileCheck2 },
    ],
  },
]

const accountNavigation = [
  { label: 'My profile', href: '/my-profile', icon: UserRoundCheck },
  { label: 'Membership card', href: '/my-membership-card', icon: CreditCard },
  { label: 'Directory privacy', href: '/directory-privacy', icon: BookUser },
  { label: 'Hybrid app', href: '/hybrid', icon: LayoutDashboard },
]

function isActive(href) {
  if (!href || href.startsWith('#')) {
    return false
  }

  return route.path === href || route.path.startsWith(`${href}/`)
}

function groupContainsActiveRoute(group) {
  return group.items.some((item) => isActive(item.href))
}

function isGroupExpanded(groupId) {
  return expandedGroups.value.has(groupId)
}

function toggleGroup(groupId) {
  const next = new Set(expandedGroups.value)

  if (next.has(groupId)) {
    next.delete(groupId)
  } else {
    next.add(groupId)
  }

  expandedGroups.value = next
}

function expandActiveGroups() {
  const next = new Set(expandedGroups.value)

  navigationGroups.forEach((group) => {
    if (groupContainsActiveRoute(group)) {
      next.add(group.id)
    }
  })

  expandedGroups.value = next
}

watch(() => route.path, expandActiveGroups, { immediate: true })
</script>
