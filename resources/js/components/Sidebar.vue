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
      <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-white/45">Overview</p>
      <a v-for="item in overviewNavigation" :key="item.label" :href="item.href" :class="navigationClass(item.active)">
        <component :is="item.icon" :size="18" aria-hidden="true" />
        <span>{{ item.label }}</span>
      </a>
      
      <p class="mt-6 px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-white/45">Ministry</p>
      <a v-for="item in ministryNavigation" :key="item.label" :href="item.href" :class="navigationClass(false)">
        <component :is="item.icon" :size="18" aria-hidden="true" />
        <span>{{ item.label }}</span>
        <span v-if="item.count" class="ml-auto rounded-full bg-accent px-2 py-0.5 text-[11px] font-semibold text-brand">{{ item.count }}</span>
      </a>
      
      <p class="mt-6 px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-white/45">Organization</p>
      <a v-for="item in organizationNavigation" :key="item.label" :href="item.href" :class="navigationClass(false)">
        <component :is="item.icon" :size="18" aria-hidden="true" />
        <span>{{ item.label }}</span>
        <span v-if="item.count" class="ml-auto rounded-full bg-accent px-2 py-0.5 text-[11px] font-semibold text-brand">{{ item.count }}</span>
      </a>

  
    </nav>
    
    <div class="border-t border-white/10 p-3">
      <a href="#settings" :class="navigationClass(false)">
        <Settings :size="18" aria-hidden="true" />
        <span>Settings</span>
      </a>
    </div>
  </aside>
</template>

<script setup>
import { computed } from 'vue'
import { 
  ArrowRightLeft,
  BarChart3, 
  Bell, 
  CheckCircle2, 
  ChevronDown, 
  ChevronRight, 
  CircleDollarSign, 
  Clock3, 
  FileCheck2, 
  HeartHandshake, 
  LayoutDashboard, 
  LogOut, 
  Mail, 
  MapPin, 
  Menu, 
  MessageSquareText, 
  Settings, 
  ShieldCheck, 
  UserPlus, 
  Users, 
  UserRoundCheck, 
  X,
  Building,
  TreePine
} from '@lucide/vue'
import { useAuthStore } from '../stores/auth'

const authStore = useAuthStore()

// This will be passed down from parent component
defineProps({
  drawerOpen: Boolean
})

const navigationClass = (active) => ['mb-1 flex min-h-11 items-center gap-3 rounded-md px-3 text-sm font-medium transition-colors', active ? 'bg-white/12 text-white' : 'text-white/70 hover:bg-white/8 hover:text-white']

const overviewNavigation = [
  { label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard, active: true },
  { label: 'People', href: '#people', icon: Users },
  { label: 'Attendance', href: '#attendance', icon: UserRoundCheck }
]

const ministryNavigation = [
  { label: 'Teams & groups', href: '#teams', icon: HeartHandshake },
  { label: 'Care & welfare', href: '#care', icon: ShieldCheck, count: 3 },
  { label: 'Communications', href: '#communications', icon: Mail },
  { label: 'Reports', href: '#reports', icon: BarChart3 }
]

const organizationNavigation = [
  { label: 'Organization Structure', href: '/organizations', icon: TreePine },
  { label: 'Member Movements', href: '/movements', icon: ArrowRightLeft },
  { label: 'Branches & Locations', href: '#branches', icon: Building },
  { label: 'Role Management', href: '/roles', icon: ShieldCheck },
  { label: 'Configuration', href: '/config', icon: Settings }
]

</script>