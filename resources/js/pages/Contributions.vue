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
            <p class="truncate text-sm font-semibold text-ink">Contributions</p>
            <p class="truncate text-xs text-muted">Reconcile giving and issue verifiable receipts</p>
          </div>
        </div>
      </header>

      <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
        <section class="mb-6 border-b border-line pb-6">
          <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-brand">Giving</p>
          <h1 class="font-serif text-3xl font-bold">Reconciliation</h1>
        </section>

        <p v-if="store.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ store.error }}</p>

        <div class="grid gap-6 lg:grid-cols-2">
          <div class="space-y-6">
            <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createManual">
              <h2 class="font-semibold">Manual contribution</h2>
              <input v-model.number="manual.amount_cents" type="number" required min="1" placeholder="Amount (cents)" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <select v-model="manual.currency" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
              </select>
              <select v-model="manual.category" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
              </select>
              <input v-model="manual.branch_id" type="number" placeholder="Branch ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="manual.member_id" type="number" placeholder="Member ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="manual.payment_reference" placeholder="Payment reference" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <select v-model="manual.campaign_id" class="w-full rounded-md border border-line px-3 py-2 text-sm">
                <option value="">No campaign</option>
                <option v-for="c in store.campaigns" :key="c.id" :value="String(c.id)">{{ c.code }} · {{ c.name }}</option>
              </select>
              <button type="submit" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white" :disabled="store.saving">Save</button>
            </form>

            <form class="space-y-3 rounded-md border border-line bg-white p-5" @submit.prevent="createCampaign">
              <h2 class="font-semibold">Campaign</h2>
              <input v-model="campaign.name" required placeholder="Name" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="campaign.code" required placeholder="Code" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <input v-model="campaign.branch_id" type="number" placeholder="Branch ID" class="w-full rounded-md border border-line px-3 py-2 text-sm" />
              <button type="submit" class="rounded-md border border-line px-4 py-2 text-sm font-semibold" :disabled="store.saving">Create campaign</button>
            </form>
          </div>

          <section class="space-y-6">
            <div class="rounded-md border border-line bg-white p-5">
              <h2 class="mb-3 font-semibold">Queue</h2>
              <ul class="space-y-2 text-sm">
                <li v-for="item in store.items" :key="item.id" class="rounded-md border border-line p-3">
                  <button type="button" class="w-full text-left" @click="openItem(item.id)">
                    <p class="font-medium">{{ item.reference }} · {{ item.amount_cents }} {{ item.currency }}</p>
                    <p class="text-xs text-muted">
                      {{ item.reconciliation_status }} · {{ item.category }} · {{ item.source_type }}
                    </p>
                  </button>
                </li>
              </ul>
            </div>

            <section v-if="store.selected" class="space-y-3 rounded-md border border-line bg-white p-5 text-sm">
              <h2 class="font-semibold">{{ store.selected.reference }}</h2>
              <p class="text-xs text-muted">
                {{ store.selected.reconciliation_status }} · {{ store.selected.amount_cents }} {{ store.selected.currency }}
                · {{ store.selected.category }}
              </p>
              <p class="text-xs text-muted">Payment ref: {{ store.selected.payment_reference || store.selected.provider_payment_reference }}</p>
              <p class="text-xs text-muted">Provider evidence preserved: {{ store.selected.provider_evidence ? 'yes' : 'n/a' }}</p>

              <div class="grid gap-2 sm:grid-cols-2">
                <input v-model="matchForm.member_id" type="number" placeholder="Member ID" class="rounded-md border border-line px-2 py-1 text-xs" />
                <input v-model="matchForm.payment_reference" placeholder="Payment reference" class="rounded-md border border-line px-2 py-1 text-xs" />
                <select v-model="matchForm.category" class="rounded-md border border-line px-2 py-1 text-xs">
                  <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                </select>
                <select v-model="matchForm.campaign_id" class="rounded-md border border-line px-2 py-1 text-xs">
                  <option value="">Campaign</option>
                  <option v-for="c in store.campaigns" :key="c.id" :value="String(c.id)">{{ c.code }}</option>
                </select>
              </div>

              <div class="flex flex-wrap gap-2">
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="match">Match</button>
                <button type="button" class="rounded-md bg-brand px-2 py-1 text-xs font-semibold text-white" :disabled="store.saving" @click="reconcile">Reconcile</button>
                <button type="button" class="rounded-md border border-line px-2 py-1 text-xs" :disabled="store.saving" @click="issueReceipt">Issue receipt</button>
                <button
                  v-if="store.selected.active_receipt"
                  type="button"
                  class="rounded-md border border-line px-2 py-1 text-xs"
                  :disabled="store.saving"
                  @click="voidReceipt"
                >
                  Void receipt
                </button>
              </div>

              <div v-if="store.selected.active_receipt" class="rounded-md border border-line bg-canvas p-3 text-xs">
                <p class="font-medium">Receipt {{ store.selected.active_receipt.receipt_number }}</p>
                <p class="text-muted">Verify: {{ store.selected.active_receipt.verification_code }} · delivered {{ store.selected.active_receipt.delivered }}</p>
              </div>

              <div v-if="store.selected.adjustments?.length" class="border-t border-line pt-3 text-xs text-muted">
                <h3 class="mb-1 text-sm font-medium text-ink">Adjustments</h3>
                <p v-for="row in store.selected.adjustments" :key="row.id">
                  {{ row.reference }} · {{ row.adjustment_type }} · {{ row.reason }}
                </p>
              </div>
            </section>
          </section>
        </div>
      </main>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import { Menu } from '@lucide/vue'
import { useContributionsStore } from '../stores/contributions'

const store = useContributionsStore()
const drawerOpen = ref(false)
const currencies = ['USD', 'EUR', 'GBP', 'NGN', 'GHS', 'KES', 'ZAR']
const categories = ['tithe', 'offering', 'building_fund', 'missions', 'welfare', 'event', 'other']

const manual = reactive({
  amount_cents: 1000,
  currency: 'USD',
  category: 'tithe',
  branch_id: '',
  member_id: '',
  payment_reference: '',
  campaign_id: '',
})

const campaign = reactive({
  name: '',
  code: '',
  branch_id: '',
})

const matchForm = reactive({
  member_id: '',
  payment_reference: '',
  category: 'tithe',
  campaign_id: '',
})

async function createManual() {
  await store.createManual({
    amount_cents: Number(manual.amount_cents),
    currency: manual.currency,
    category: manual.category,
    branch_id: manual.branch_id ? Number(manual.branch_id) : null,
    member_id: manual.member_id ? Number(manual.member_id) : null,
    payment_reference: manual.payment_reference || null,
    campaign_id: manual.campaign_id ? Number(manual.campaign_id) : null,
  })
}

async function createCampaign() {
  await store.createCampaign({
    name: campaign.name,
    code: campaign.code,
    branch_id: campaign.branch_id ? Number(campaign.branch_id) : null,
  })
  campaign.name = ''
  campaign.code = ''
}

async function openItem(id) {
  await store.select(id)
  matchForm.member_id = store.selected?.member_id ? String(store.selected.member_id) : ''
  matchForm.payment_reference = store.selected?.payment_reference || ''
  matchForm.category = store.selected?.category || 'tithe'
  matchForm.campaign_id = store.selected?.campaign_id ? String(store.selected.campaign_id) : ''
}

async function match() {
  if (!store.selected) return
  await store.match(store.selected.id, {
    member_id: matchForm.member_id ? Number(matchForm.member_id) : null,
    payment_reference: matchForm.payment_reference || null,
    category: matchForm.category,
    campaign_id: matchForm.campaign_id ? Number(matchForm.campaign_id) : null,
    branch_id: store.selected.branch_id,
  })
}

async function reconcile() {
  if (!store.selected) return
  await store.reconcile(store.selected.id, {
    resolution_notes: store.selected.reconciliation_status === 'needs_resolution' ? 'Resolved in UI' : undefined,
  })
}

async function issueReceipt() {
  if (!store.selected) return
  await store.issueReceipt(store.selected.id)
}

async function voidReceipt() {
  if (!store.selected?.active_receipt) return
  await store.voidReceipt(store.selected.active_receipt.id, 'Voided via UI')
}

onMounted(async () => {
  await store.fetchCampaigns()
  await store.fetchItems()
})
</script>
