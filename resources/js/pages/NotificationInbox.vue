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
            <p class="truncate text-sm font-semibold text-ink">Notification inbox</p>
            <p class="truncate text-xs text-muted">
              {{ store.unreadCount }} unread
            </p>
          </div>
          <button type="button" class="rounded-md border border-line px-3 py-2 text-xs font-semibold" :disabled="store.saving || store.loading" @click="markAll">
            Mark all read
          </button>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Engage</p>
          <h1 class="font-serif text-3xl font-bold">Inbox</h1>
        </section>

        <div class="mb-4 flex flex-wrap gap-2">
          <button
            type="button"
            class="rounded-md border px-3 py-1.5 text-xs font-semibold"
            :class="filter.category === '' ? 'border-brand bg-brand/10 text-brand' : 'border-line'"
            @click="setCategory('')"
          >
            All
          </button>
          <button
            v-for="(label, key) in store.categories"
            :key="key"
            type="button"
            class="rounded-md border px-3 py-1.5 text-xs font-semibold"
            :class="filter.category === key ? 'border-brand bg-brand/10 text-brand' : 'border-line'"
            @click="setCategory(key)"
          >
            {{ label }}
          </button>
          <label class="ml-auto flex items-center gap-2 text-xs text-muted">
            <input v-model="filter.unread_only" type="checkbox" class="rounded border-line" @change="reload" />
            Unread only
          </label>
        </div>

        <p v-if="store.loading" class="rounded-md border border-line bg-white px-4 py-6 text-sm text-muted" role="status">
          Loading notifications…
        </p>
        <p v-else-if="store.error" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {{ store.error }}
        </p>
        <p v-else-if="store.empty" class="rounded-md border border-line bg-white px-4 py-10 text-center text-sm text-muted">
          No notifications yet. You’re all caught up.
        </p>

        <ul v-else class="space-y-2" aria-live="polite">
          <li
            v-for="item in store.items"
            :key="item.id"
            class="rounded-md border border-line bg-white p-4"
            :class="item.is_read ? 'opacity-80' : 'border-brand/40'"
          >
            <div class="flex flex-wrap items-start gap-3">
              <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted">{{ item.category_label }}</p>
                <p class="mt-1 text-sm font-medium">{{ item.message }}</p>
                <p class="mt-1 text-xs text-muted">{{ item.created_at }} · {{ item.is_read ? 'Read' : 'Unread' }}</p>
              </div>
              <div class="flex flex-wrap gap-2">
                <button
                  v-if="item.deep_link"
                  type="button"
                  class="rounded-md bg-brand px-2 py-1 text-xs font-semibold text-white"
                  :disabled="store.saving"
                  @click="openItem(item)"
                >
                  Open
                </button>
                <button
                  type="button"
                  class="rounded-md border border-line px-2 py-1 text-xs"
                  :disabled="store.saving"
                  @click="item.is_read ? store.markUnread(item.id) : store.markRead(item.id)"
                >
                  {{ item.is_read ? 'Mark unread' : 'Mark read' }}
                </button>
                <button
                  type="button"
                  class="rounded-md border border-line px-2 py-1 text-xs"
                  :disabled="store.saving"
                  @click="store.archive(item.id)"
                >
                  Archive
                </button>
              </div>
            </div>
          </li>
        </ul>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useNotificationsStore } from '../stores/notifications'

const store = useNotificationsStore()
const router = useRouter()
const drawerOpen = ref(false)

const filter = reactive({
  category: '',
  unread_only: false,
})

function queryParams() {
  const params = {}
  if (filter.category) params.category = filter.category
  if (filter.unread_only) params.unread_only = 1
  return params
}

async function reload() {
  await store.fetchInbox(queryParams())
}

async function setCategory(key) {
  filter.category = key
  await reload()
}

async function markAll() {
  await store.markAllRead()
}

async function openItem(item) {
  try {
    const result = await store.open(item.id)
    if (result?.deep_link) {
      await router.push(result.deep_link)
    }
  } catch {
    // error already on store
  }
}

onMounted(async () => {
  await reload()
})
</script>
