<script setup lang="ts">
import { ref, onMounted, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

// Small helper: read XSRF token cookie
function xsrf(): string {
  try {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
    return m ? decodeURIComponent(m[1]) : ''
  } catch { return '' }
}

async function http(url: string, options: RequestInit = {}) {
  const isGet = !options.method || options.method.toUpperCase() === 'GET'
  const headers: HeadersInit = {
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    ...(!isGet ? { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() } : {}),
    ...(options.headers || {}),
  }
  const res = await fetch(url, { credentials: 'same-origin', ...options, headers })
  if (!res.ok) throw new Error(await res.text())
  if (res.status === 204) return null
  return res.json()
}

function formatKyivDateTime(input?: string | number | Date): string {
  if (!input) return ''
  const d = new Date(input)
  if (isNaN(d.getTime())) return ''
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Europe/Kyiv', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hour12: false,
  }).formatToParts(d)
  const map: Record<string,string> = {}
  for (const p of parts) map[p.type] = p.value
  return `${map.year}-${map.month}-${map.day} ${map.hour}:${map.minute}`
}

// My PDPs snapshot state
interface OverviewItem {
  id: number
  title: string
  role: 'owner' | 'curator'
  status: string
  eta?: string | null
  totalCriteria: number
  closed: number
  remaining: number
  updated_at: string
  owner?: { id: number; name?: string; email?: string | null }
}
const overview = ref<OverviewItem[] | null>(null)
const overviewLoading = ref(false)
const overviewError = ref('')

async function loadOverview() {
  overviewLoading.value = true
  overviewError.value = ''
  try {
    overview.value = await http('/dashboard/pdps/overview.json')
  } catch (e: any) {
    overviewError.value = e?.message || 'Failed to load overview'
    overview.value = []
  } finally {
    overviewLoading.value = false
  }
}

// Pending approvals state
interface PendingItem {
  id: number
  pdp: { id: number; title: string }
  skill: { id: number; name: string }
  criterion: { index: number; text: string }
  note: string
  created_at: string
  owner?: { id: number; name?: string; email?: string }
}
const pending = ref<PendingItem[] | null>(null)
const loading = ref(false)
const error = ref('')

async function loadPending() {
  loading.value = true
  error.value = ''
  try {
    pending.value = await http('/dashboard/pending-approvals.json')
  } catch (e: any) {
    error.value = e?.message || 'Failed to load pending approvals'
    pending.value = []
  } finally {
    loading.value = false
  }
}

// PDP selector + summary state (KPI + micro sparkline)
interface PdpOption { id: number; title: string }
const pdps = ref<PdpOption[]>([])
const selectedPdpId = ref<number | null>(null)

interface PdpSummary {
  totalCriteria: number
  approvedCount: number
  pendingCount: number
  avgApproveHours: number | null
  medianApproveHours: number | null
  wins: { date: string; count: number }[]
  skills: { id: number; skill: string; totalCriteria: number; approvedCount: number; pendingCount: number }[]
}

const summary = ref<PdpSummary | null>(null)
const summaryLoading = ref(false)
const summaryError = ref('')

// Recent closures (last 5 days)
interface RecentClosure {
  id: number
  pdp: { id: number; title: string }
  skill: { id: number; name: string }
  criterion: { index: number; text: string }
  note: string
  closed_at: string
}
const recent = ref<RecentClosure[] | null>(null)
const recentLoading = ref(false)
const recentError = ref('')

async function loadRecent() {
  recentLoading.value = true
  recentError.value = ''
  try {
    const q = selectedPdpId.value ? `?pdp=${selectedPdpId.value}` : ''
    recent.value = await http(`/dashboard/recent-closures.json${q}`)
  } catch (e: any) {
    recentError.value = e?.message || 'Failed to load recent closures'
    recent.value = []
  } finally {
    recentLoading.value = false
  }
}

async function loadPdps() {
  try {
    const [own, shared] = await Promise.all([
      http('/pdps.json'),
      http('/pdps.shared.json').catch(() => []),
    ])
    const map = new Map<number, PdpOption>()
    for (const it of own || []) map.set(it.id, { id: it.id, title: it.title })
    for (const it of shared || []) if (!map.has(it.id)) map.set(it.id, { id: it.id, title: it.title })
    pdps.value = Array.from(map.values())
    if (selectedPdpId.value == null && pdps.value.length) {
      selectedPdpId.value = pdps.value[0].id
    }
  } catch {
    // ignore
  }
}

async function loadSummary() {
  if (!selectedPdpId.value) { summary.value = { totalCriteria: 0, approvedCount: 0, pendingCount: 0, avgApproveHours: null, medianApproveHours: null, wins: [], skills: [] }; return }
  summaryLoading.value = true
  summaryError.value = ''
  try {
    summary.value = await http(`/dashboard/pdps/${selectedPdpId.value}/summary.json`)
  } catch (e: any) {
    summaryError.value = e?.message || 'Failed to load summary'
    summary.value = { totalCriteria: 0, approvedCount: 0, pendingCount: 0, avgApproveHours: null, medianApproveHours: null, wins: [], skills: [] }
  } finally {
    summaryLoading.value = false
  }
}

watch(selectedPdpId, () => { loadSummary(); loadRecent() })

onMounted(() => { loadOverview(); loadPending(); loadPdps().then(() => { loadSummary(); loadRecent() }) })
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
        >
            <div class="grid auto-rows-min gap-4 md:grid-cols-3">
                <!-- Left-top: My PDPs snapshot -->
                <div class="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-3">
                  <div class="mb-2 flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold">My PDPs snapshot</h3>
                    <button class="rounded border px-2 py-0.5 text-[11px] hover:bg-muted" @click="loadOverview">Refresh</button>
                  </div>
                  <div v-if="overviewLoading" class="text-xs text-muted-foreground">Loading…</div>
                  <div v-else-if="overviewError" class="text-xs text-destructive">{{ overviewError }}</div>
                  <div v-else>
                    <div v-if="(overview || []).length" class="max-h-64 overflow-auto divide-y">
                      <div v-for="it in (overview || [])" :key="it.id" class="py-2 text-sm">
                        <div class="flex items-center justify-between gap-2">
                          <div class="truncate">
                            <div class="font-medium flex items-center gap-2">
                              <span class="truncate">{{ it.title }}</span>
                              <span class="shrink-0 inline-flex items-center rounded-full px-2 py-0.5 text-[10px]" :class="it.role==='owner' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' : 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300'">{{ it.role }}</span>
                            </div>
                            <div class="text-[11px] text-muted-foreground">
                              {{ it.status }}<span v-if="it.eta"> · ETA: {{ it.eta }}</span>
                            </div>
                          </div>
                          <div class="text-right">
                            <div class="text-[11px] text-muted-foreground whitespace-nowrap">{{ Math.min(it.closed, it.totalCriteria) }} / {{ it.totalCriteria }}</div>
                            <div class="mt-1 h-1.5 w-28 overflow-hidden rounded bg-muted">
                              <div class="h-full bg-primary" :style="{ width: (it.totalCriteria ? Math.min(100, Math.round(100 * it.closed / it.totalCriteria)) : 0) + '%' }"></div>
                            </div>
                          </div>
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                          <a :href="`/pdps?tab=manage&pdp=${it.id}`" class="rounded border px-2 py-0.5 text-[11px] hover:bg-muted">Open</a>
                          <a :href="`/pdps?tab=annex&pdp=${it.id}`" class="rounded border px-2 py-0.5 text-[11px] hover:bg-muted">Annex</a>
                        </div>
                      </div>
                    </div>
                    <div v-else class="text-xs text-muted-foreground">No available PDPs.</div>
                  </div>
                </div>
                <!-- Middle-top: Recently closed (last 5 days) -->
                <div class="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-3">
                  <div class="mb-2 flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold">Closed in last 5 days</h3>
                    <div class="flex items-center gap-2">
                      <button class="rounded border px-2 py-0.5 text-[11px] hover:bg-muted" @click="loadRecent">Refresh</button>
                    </div>
                  </div>
                  <div v-if="recentLoading" class="text-xs text-muted-foreground">Loading…</div>
                  <div v-else-if="recentError" class="text-xs text-destructive">{{ recentError }}</div>
                  <div v-else>
                    <div v-if="(recent || []).length" class="max-h-64 overflow-auto divide-y">
                      <div v-for="it in (recent || [])" :key="it.id" class="py-2 text-sm">
                        <div class="flex items-center justify-between gap-2">
                          <div class="truncate">
                            <div class="font-medium truncate">{{ it.pdp.title }}</div>
                            <div class="text-[11px] text-muted-foreground truncate">{{ it.skill.name }} • {{ it.criterion.text }}</div>
                          </div>
                          <div class="text-[11px] text-muted-foreground whitespace-nowrap">{{ formatKyivDateTime(it.closed_at) }}</div>
                        </div>
                        <div class="mt-2">
                          <a :href="`/pdps?tab=manage&pdp=${it.pdp.id}&skill=${it.skill.id}&criterion=${it.criterion.index}`" class="rounded border px-2 py-0.5 text-[11px] hover:bg-muted">Open PDP</a>
                        </div>
                      </div>
                    </div>
                    <div v-else class="text-xs text-muted-foreground">No closures in the last 5 days.</div>
                  </div>
                </div>
                <!-- Right-top: Pending approvals list -->
                <div class="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-3">
                  <div class="mb-2 flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold">Pending approvals</h3>
                    <button class="rounded border px-2 py-0.5 text-[11px] hover:bg-muted" @click="loadPending">Refresh</button>
                  </div>
                  <div v-if="loading" class="text-xs text-muted-foreground">Loading…</div>
                  <div v-else-if="error" class="text-xs text-destructive">{{ error }}</div>
                  <div v-else>
                    <div v-if="(pending || []).length" class="max-h-64 overflow-auto divide-y">
                      <div v-for="it in (pending || [])" :key="it.id" class="py-2 text-sm">
                        <div class="flex items-center justify-between gap-2">
                          <div class="truncate">
                            <div class="font-medium truncate">{{ it.pdp.title }}</div>
                            <div class="text-[11px] text-muted-foreground truncate">{{ it.skill.name }} • {{ it.criterion.text }}</div>
                          </div>
                          <div class="text-[11px] text-muted-foreground whitespace-nowrap">{{ formatKyivDateTime(it.created_at) }}</div>
                        </div>
                        <div v-if="it.owner" class="mt-0.5 text-[11px] text-muted-foreground truncate">Owner: {{ it.owner.name || it.owner.email }}</div>
                        <div v-if="it.note" class="mt-1 line-clamp-2 text-xs text-muted-foreground">{{ it.note }}</div>
                        <div class="mt-2">
                          <a :href="`/pdps?tab=manage&pdp=${it.pdp.id}&skill=${it.skill.id}&criterion=${it.criterion.index}`" class="rounded border px-2 py-0.5 text-[11px] hover:bg-muted">Open PDP</a>
                        </div>
                      </div>
                    </div>
                    <div v-else class="text-xs text-muted-foreground">No pending entries.</div>
                  </div>
                </div>
            </div>
            <!-- Bottom: PDP Progress — KPI tiles + micro sparkline -->
            <div class="relative flex-1 rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-3">
              <div class="mb-3 flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold">PDP Progress</h3>
                <div class="flex items-center gap-2">
                  <label class="text-xs text-muted-foreground">PDP:</label>
                  <select v-model.number="selectedPdpId" class="rounded border bg-background px-2 py-1 text-sm">
                    <option v-if="!pdps.length" disabled value="">Loading…</option>
                    <option v-for="p in pdps" :key="p.id" :value="p.id">{{ p.title }}</option>
                  </select>
                </div>
              </div>
              <div v-if="summaryLoading" class="text-xs text-muted-foreground">Loading…</div>
              <div v-else-if="summaryError" class="text-xs text-destructive">{{ summaryError }}</div>
              <div v-else>
                <div v-if="summary" class="space-y-3">
                  <!-- KPI tiles -->
                  <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <div class="rounded-lg border p-3">
                      <div class="text-[11px] text-muted-foreground">Total criteria</div>
                      <div class="mt-1 text-xl font-semibold">{{ summary.totalCriteria }}</div>
                    </div>
                    <div class="rounded-lg border p-3">
                      <div class="text-[11px] text-muted-foreground">Closed</div>
                      <div class="mt-1 text-xl font-semibold">{{ summary.approvedCount }}</div>
                    </div>
                    <div class="rounded-lg border p-3">
                      <div class="text-[11px] text-muted-foreground">Remaining</div>
                      <div class="mt-1 text-xl font-semibold">{{ summary.pendingCount }}</div>
                    </div>
                    <div class="rounded-lg border p-3">
                      <div class="text-[11px] text-muted-foreground">Median time to approval</div>
                      <div class="mt-1 text-xl font-semibold">{{ summary.medianApproveHours != null ? summary.medianApproveHours + ' h' : '—' }}</div>
                      <div class="text-[11px] text-muted-foreground" v-if="summary.avgApproveHours != null">Ø {{ summary.avgApproveHours }} h</div>
                    </div>
                  </div>
                  <!-- Skills breakdown (per-skill closed/opened) -->
                  <div>
                    <div class="text-[11px] text-muted-foreground mb-2">Per-skill status</div>
                    <div v-if="(summary.skills || []).length" class="grid gap-2 md:grid-cols-2 lg:grid-cols-3">
                      <div v-for="s in summary.skills" :key="s.id" class="relative overflow-hidden rounded border p-2">
                        <div class="absolute right-0 top-0 h-full w-1" :class="s.approvedCount===0 ? 'bg-red-500' : (s.pendingCount===0 ? 'bg-green-500' : 'bg-blue-500')"></div>
                        <div class="text-sm font-medium truncate pr-2">{{ s.skill }}</div>
                        <div class="mt-1 text-[12px] text-muted-foreground pr-2">Total: {{ s.totalCriteria }}</div>
                        <div class="mt-0.5 text-[12px] pr-2">
                          <span class="font-medium">Closed:</span> {{ s.approvedCount }}
                          <span class="ml-3 font-medium">Open:</span> {{ s.pendingCount }}
                        </div>
                      </div>
                    </div>
                    <div v-else class="text-xs text-muted-foreground">No skills in the selected PDP.</div>
                  </div>
                </div>
                <div v-else class="text-xs text-muted-foreground">No data.</div>
              </div>
            </div>
        </div>
    </AppLayout>
</template>
