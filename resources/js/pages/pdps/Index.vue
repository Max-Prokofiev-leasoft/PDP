<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head } from '@inertiajs/vue3'
import Heading from '@/components/Heading.vue'

// Types
export type Pdp = {
  id: number
  title: string
  description?: string
  priority: 'Low' | 'Medium' | 'High'
  eta?: string
  status: 'Planned' | 'In Progress' | 'Done' | 'Blocked'
  skills_count?: number
}

export type PdpSkill = {
  id: number
  pdp_id: number
  skill: string
  description?: string
  criteria?: string
  priority: 'Low' | 'Medium' | 'High'
  eta?: string
  status: 'Planned' | 'In Progress' | 'Done' | 'Blocked'
  order_column?: number
}

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'PDP list', href: '/pdps' },
]

// State
const pdps = ref<Pdp[]>([])
const selectedPdpId = ref<number | null>(null)
const skills = ref<PdpSkill[]>([])

const showPdpModal = ref(false)
const editingPdpId = ref<number | null>(null)
const pdpForm = reactive<Pdp>({ id: 0, title: '', description: '', priority: 'Medium', eta: '', status: 'Planned' })

const showSkillModal = ref(false)
const editingSkillId = ref<number | null>(null)
const skillForm = reactive<PdpSkill>({ id: 0, pdp_id: 0, skill: '', description: '', criteria: '', priority: 'Medium', eta: '', status: 'Planned' })

const csrf = () => (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content || ''

// Helpers
async function http(url: string, options: RequestInit = {}) {
  const headers: HeadersInit = {
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    ...(options.method && options.method !== 'GET' ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() } : {}),
    ...(options.headers || {}),
  }
  const res = await fetch(url, { credentials: 'same-origin', ...options, headers })
  if (!res.ok) {
    const msg = await res.text()
    throw new Error(msg || `Request failed: ${res.status}`)
  }
  if (res.status === 204) return null
  return res.json()
}

// Loaders
async function loadPdps() {
  pdps.value = await http('/pdps.json')
  if (pdps.value.length && !selectedPdpId.value) {
    selectPdp(pdps.value[0].id)
  }
}

async function loadSkills(pdpId: number) {
  skills.value = await http(`/pdps/${pdpId}/skills.json`)
}

function selectPdp(id: number) {
  selectedPdpId.value = id
  loadSkills(id)
}

// PDP modal
function openCreatePdp() {
  editingPdpId.value = null
  Object.assign(pdpForm, { id: 0, title: '', description: '', priority: 'Medium', eta: '', status: 'Planned' })
  showPdpModal.value = true
}

function openEditPdp(p: Pdp) {
  editingPdpId.value = p.id
  Object.assign(pdpForm, { ...p })
  showPdpModal.value = true
}

async function savePdp() {
  if (!pdpForm.title.trim()) return alert('Вкажіть назву PDP')
  const body = JSON.stringify({ title: pdpForm.title, description: pdpForm.description, priority: pdpForm.priority, eta: pdpForm.eta, status: pdpForm.status })
  if (editingPdpId.value) {
    await http(`/pdps/${editingPdpId.value}.json`, { method: 'PUT', body })
  } else {
    const created = await http('/pdps.json', { method: 'POST', body })
    selectedPdpId.value = created.id
  }
  showPdpModal.value = false
  await loadPdps()
  if (selectedPdpId.value) await loadSkills(selectedPdpId.value)
}

async function deletePdp(id: number) {
  if (!confirm('Видалити цей PDP та всі його скіли?')) return
  await http(`/pdps/${id}.json`, { method: 'DELETE' })
  if (selectedPdpId.value === id) selectedPdpId.value = null
  await loadPdps()
}

// Skills modal
function openCreateSkill() {
  if (!selectedPdpId.value) return
  editingSkillId.value = null
  Object.assign(skillForm, { id: 0, pdp_id: selectedPdpId.value, skill: '', description: '', criteria: '', priority: 'Medium', eta: '', status: 'Planned' })
  showSkillModal.value = true
}

function openEditSkill(s: PdpSkill) {
  editingSkillId.value = s.id
  Object.assign(skillForm, { ...s })
  showSkillModal.value = true
}

async function saveSkill() {
  if (!selectedPdpId.value) return
  if (!skillForm.skill.trim()) return alert('Заповніть поле "Skill to achieve"')
  const body = JSON.stringify({ skill: skillForm.skill, description: skillForm.description, criteria: skillForm.criteria, priority: skillForm.priority, eta: skillForm.eta, status: skillForm.status })
  if (editingSkillId.value) {
    await http(`/pdps/${selectedPdpId.value}/skills/${editingSkillId.value}.json`, { method: 'PUT', body })
  } else {
    await http(`/pdps/${selectedPdpId.value}/skills.json`, { method: 'POST', body })
  }
  showSkillModal.value = false
  await loadSkills(selectedPdpId.value)
}

async function deleteSkill(id: number) {
  if (!selectedPdpId.value) return
  if (!confirm('Видалити скіл?')) return
  await http(`/pdps/${selectedPdpId.value}/skills/${id}.json`, { method: 'DELETE' })
  await loadSkills(selectedPdpId.value)
}

const hasPdps = computed(() => pdps.value.length > 0)
const selectedPdp = computed(() => pdps.value.find(p => p.id === selectedPdpId.value) || null)

onMounted(() => {
  loadPdps()
})
</script>

<template>
  <Head title="PDP List" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
      <Heading title="PDP list" description="PDP — це план, який містить список скілів/задач для досягнення." />

      <div class="flex flex-col gap-4">
        <!-- PDP list (top) -->
        <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
          <div class="mb-3 flex items-center justify-between">
            <h2 class="text-base font-semibold">Ваші PDP</h2>
            <button class="rounded-md bg-primary px-3 py-2 text-xs font-medium text-primary-foreground hover:opacity-90" @click="openCreatePdp">+ Add PDP</button>
          </div>

          <div v-if="hasPdps" class="space-y-1">
            <button v-for="p in pdps" :key="p.id" class="w-full rounded-md border px-3 py-2 text-left text-sm hover:bg-muted" :class="selectedPdpId===p.id ? 'border-primary' : 'border-border'" @click="selectPdp(p.id)">
              <div class="flex items-center justify-between">
                <span class="font-medium">{{ p.title }}</span>
                <span class="text-xs text-muted-foreground">{{ p.skills_count ?? 0 }} skills</span>
              </div>
              <div class="text-xs text-muted-foreground">{{ p.status }} · {{ p.priority }}<span v-if="p.eta"> · ETA: {{ p.eta }}</span></div>
              <div class="mt-2 flex gap-2">
                <button class="rounded border px-2 py-1 text-[11px] hover:bg-muted" @click.stop="openEditPdp(p)">Edit</button>
                <button class="rounded border px-2 py-1 text-[11px] text-destructive hover:bg-destructive hover:text-destructive-foreground" @click.stop="deletePdp(p.id)">Delete</button>
              </div>
            </button>
          </div>
          <p v-else class="text-sm text-muted-foreground">Список порожній. Додайте перший PDP.</p>
        </div>

        <!-- Skills of selected PDP (below) -->
        <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
          <div class="mb-3 flex items-center justify-between">
            <h2 class="text-base font-semibold">Скіли в PDP</h2>
            <div v-if="selectedPdp">
              <button class="rounded-md bg-primary px-3 py-2 text-xs font-medium text-primary-foreground hover:opacity-90" @click="openCreateSkill">+ Add Skill</button>
            </div>
          </div>

          <template v-if="selectedPdp">
            <p class="mb-3 text-sm text-muted-foreground">{{ selectedPdp.description }}</p>
            <div v-if="skills.length" class="overflow-x-auto">
              <table class="min-w-full text-sm">
                <thead>
                  <tr class="border-b text-left text-muted-foreground">
                    <th class="px-3 py-2">Skill to achieve</th>
                    <th class="px-3 py-2">Description</th>
                    <th class="px-3 py-2">Win Criteria</th>
                    <th class="px-3 py-2">Prio</th>
                    <th class="px-3 py-2">ETA</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="s in skills" :key="s.id" class="border-b align-top">
                    <td class="px-3 py-3 font-medium">{{ s.skill }}</td>
                    <td class="px-3 py-3 whitespace-pre-line">{{ s.description }}</td>
                    <td class="px-3 py-3 whitespace-pre-line">{{ s.criteria }}</td>
                    <td class="px-3 py-3">{{ s.priority }}</td>
                    <td class="px-3 py-3">{{ s.eta }}</td>
                    <td class="px-3 py-3">{{ s.status }}</td>
                    <td class="px-3 py-3 text-right">
                      <div class="flex justify-end gap-2">
                        <button class="rounded border px-2 py-1 text-xs hover:bg-muted" @click="openEditSkill(s)">Edit</button>
                        <button class="rounded border px-2 py-1 text-xs text-destructive hover:bg-destructive hover:text-destructive-foreground" @click="deleteSkill(s.id)">Delete</button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-else class="text-sm text-muted-foreground">Немає скілів у цьому PDP. Додайте перший.</p>
          </template>
          <p v-else class="text-sm text-muted-foreground">Оберіть PDP ліворуч, щоб переглянути його скіли.</p>
        </div>
      </div>

      <!-- PDP Modal -->
      <div v-if="showPdpModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-2xl rounded-xl border border-border bg-background p-4 shadow-xl">
          <div class="mb-3 flex items-center justify-between">
            <h3 class="text-base font-semibold">{{ editingPdpId ? 'Edit PDP' : 'Create PDP' }}</h3>
            <button class="rounded p-1 text-muted-foreground hover:bg-muted" @click="showPdpModal=false">✕</button>
          </div>

          <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div>
              <label class="mb-1 block text-xs font-medium">PDP Title</label>
              <input v-model="pdpForm.title" type="text" class="w-full rounded-md border bg-transparent px-3 py-2 text-sm" placeholder="e.g. Promotion to Senior" />
            </div>
            <div>
              <label class="mb-1 block text-xs font-medium">Prio</label>
              <select v-model="pdpForm.priority" class="w-full rounded-md border bg-transparent px-3 py-2 text-sm">
                <option>Low</option>
                <option>Medium</option>
                <option>High</option>
              </select>
            </div>
            <div>
              <label class="mb-1 block text-xs font-medium">ETA</label>
              <input v-model="pdpForm.eta" type="text" class="w-full rounded-md border bg-transparent px-3 py-2 text-sm" placeholder="e.g. Q4 2025 or 31.12.2025" />
            </div>
            <div>
              <label class="mb-1 block text-xs font-medium">Status</label>
              <select v-model="pdpForm.status" class="w-full rounded-md border bg-transparent px-3 py-2 text-sm">
                <option>Planned</option>
                <option>In Progress</option>
                <option>Done</option>
                <option>Blocked</option>
              </select>
            </div>
            <div class="md:col-span-2">
              <label class="mb-1 block text-xs font-medium">Description</label>
              <textarea v-model="pdpForm.description" rows="4" class="w-full rounded-md border bg-transparent px-3 py-2 text-sm" placeholder="Short description of this plan"></textarea>
            </div>
          </div>

          <div class="mt-4 flex justify-end gap-2">
            <button class="rounded border px-3 py-2 text-sm hover:bg-muted" @click="showPdpModal=false">Cancel</button>
            <button class="rounded bg-primary px-3 py-2 text-sm text-primary-foreground hover:opacity-90" @click="savePdp">Save</button>
          </div>
        </div>
      </div>

      <!-- Skill Modal -->
      <div v-if="showSkillModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-3xl rounded-xl border border-border bg-background p-4 shadow-xl">
          <div class="mb-3 flex items-center justify-between">
            <h3 class="text-base font-semibold">{{ editingSkillId ? 'Edit Skill' : 'Add Skill' }}</h3>
            <button class="rounded p-1 text-muted-foreground hover:bg-muted" @click="showSkillModal=false">✕</button>
          </div>

          <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div class="md:col-span-1">
              <label class="mb-1 block text-xs font-medium">Skill to achieve</label>
              <input v-model="skillForm.skill" type="text" class="w-full rounded-md border bg-transparent px-3 py-2 text-sm" placeholder="e.g. Intermediate level of English" />
            </div>
            <div class="md:col-span-1">
              <label class="mb-1 block text-xs font-medium">Prio</label>
              <select v-model="skillForm.priority" class="w-full rounded-md border bg-transparent px-3 py-2 text-sm">
                <option>Low</option>
                <option>Medium</option>
                <option>High</option>
              </select>
            </div>

            <div class="md:col-span-1">
              <label class="mb-1 block text-xs font-medium">ETA</label>
              <input v-model="skillForm.eta" type="text" class="w-full rounded-md border bg-transparent px-3 py-2 text-sm" placeholder="e.g. Q4 2025 or 31.12.2025" />
            </div>
            <div class="md:col-span-1">
              <label class="mb-1 block text-xs font-medium">Status</label>
              <select v-model="skillForm.status" class="w-full rounded-md border bg-transparent px-3 py-2 text-sm">
                <option>Planned</option>
                <option>In Progress</option>
                <option>Done</option>
                <option>Blocked</option>
              </select>
            </div>

            <div class="md:col-span-1">
              <label class="mb-1 block text-xs font-medium">Description</label>
              <textarea v-model="skillForm.description" rows="6" class="w-full rounded-md border bg-transparent px-3 py-2 text-sm" placeholder="Short description"></textarea>
            </div>
            <div class="md:col-span-1">
              <label class="mb-1 block text-xs font-medium">Win Criteria</label>
              <textarea v-model="skillForm.criteria" rows="6" class="w-full rounded-md border bg-transparent px-3 py-2 text-sm" placeholder="Checklist or success metrics"></textarea>
            </div>
          </div>

          <div class="mt-4 flex justify-end gap-2">
            <button class="rounded border px-3 py-2 text-sm hover:bg-muted" @click="showSkillModal=false">Cancel</button>
            <button class="rounded bg-primary px-3 py-2 text-sm text-primary-foreground hover:opacity-90" @click="saveSkill">Save</button>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
