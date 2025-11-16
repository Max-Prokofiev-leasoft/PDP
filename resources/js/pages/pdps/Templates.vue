<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { Head, router } from '@inertiajs/vue3'
import Heading from '@/components/Heading.vue'
import { notifySuccess, notifyError } from '@/composables/useNotify'

interface TemplateItem {
  key: string
  title: string
  description?: string
  priority: 'Low' | 'Medium' | 'High'
  status: 'Planned' | 'In Progress' | 'Done' | 'Blocked'
  skills_count: number
}

const templates = ref<TemplateItem[]>([])
const loading = ref(false)
const assigning = ref<string | null>(null)

// Assign-to-PDP modal state
const showAssignModal = ref(false)
const assignTemplateKey = ref<string | null>(null)
type PdpOption = { id: number; title: string; status: 'Planned'|'In Progress'|'Done'|'Blocked'; skills_count?: number }
const pdpOptions = ref<PdpOption[]>([])
const selectedPdpId = ref<number>(0)
const assignLoading = ref(false)
const canSubmitAssign = computed(() => !!selectedPdpId.value)

async function openAssignModal(key: string) {
  assignTemplateKey.value = key
  showAssignModal.value = true
  selectedPdpId.value = 0
  // Load my PDPs and filter out completed ones
  try {
    assignLoading.value = true
    const list: any[] = await http('/pdps.json')
    pdpOptions.value = (Array.isArray(list) ? list : []).filter((p: any) => p && p.status !== 'Done')
  } catch (e: any) {
    notifyError('Failed to load your PDPs: ' + (e?.message || 'Error'))
    pdpOptions.value = []
  } finally {
    assignLoading.value = false
  }
}

function closeAssignModal() {
  showAssignModal.value = false
  assignTemplateKey.value = null
}

async function submitAssign() {
  if (!assignTemplateKey.value || !selectedPdpId.value) return
  assigning.value = assignTemplateKey.value
  try {
    await http(`/pdps/${selectedPdpId.value}/templates/${encodeURIComponent(assignTemplateKey.value)}/assign.json`, {
      method: 'POST',
      body: JSON.stringify({})
    })
    notifySuccess('Template skills have been added to the selected PDP')
    closeAssignModal()
    router.visit('/pdps')
  } catch (e: any) {
    notifyError('Failed to add template skills: ' + (e?.message || 'Error'))
  } finally {
    assigning.value = null
  }
}

const breadcrumbs = computed(() => [{ title: 'Skill Templates', href: '/pdps/templates' }])


// Create Template modal state
const showCreateModal = ref(false)
interface NewSkill { skill: string; description?: string; criteriaText?: string }
const newTemplate = ref<{ title: string; description?: string; skills: NewSkill[] }>({ title: '', description: '', skills: [] })
function openCreate() {
  newTemplate.value = { title: '', description: '', skills: [] }
  showCreateModal.value = true
}
function addSkillRow() { newTemplate.value.skills.push({ skill: '', description: '', criteriaText: '' }) }
function removeSkillRow(i: number) { newTemplate.value.skills.splice(i, 1) }

async function createTemplate() {
  const title = newTemplate.value.title.trim()
  if (!title) { notifyError('Please enter template title'); return }
  const skillsPayload = newTemplate.value.skills.map((s, idx) => {
    const criteriaItems = (s.criteriaText || '')
      .split(/\n+/)
      .map(t => t.trim())
      .filter(Boolean)
      .map(t => ({ text: t }))
    return {
      skill: s.skill || `Skill ${idx + 1}`,
      description: s.description || null,
      criteria: criteriaItems.length ? JSON.stringify(criteriaItems) : null,
      priority: 'Medium',
      eta: null,
      status: 'Planned',
      order_column: idx,
    }
  })
  const payload = {
    version: 1,
    pdp: { title, description: newTemplate.value.description || '', priority: 'Medium', eta: null, status: 'Planned' },
    skills: skillsPayload,
  }
  try {
    await http('/pdps/templates.json', { method: 'POST', body: JSON.stringify(payload) })
    notifySuccess('Template created')
    showCreateModal.value = false
    await loadTemplates()
  } catch (e: any) {
    notifyError('Failed to create template: ' + (e?.message || 'Error'))
  }
}

// Edit Template state
const showEditModal = ref(false)
const editingKey = ref<string | null>(null)
interface EditSkill { key?: string; skill: string; description?: string; criteriaText?: string; order_column?: number }
const editTemplate = ref<{ title: string; description?: string; skills: EditSkill[] }>({ title: '', description: '', skills: [] })

async function openEdit(key: string) {
  try {
    const data = await http(`/pdps/templates/${encodeURIComponent(key)}.json`)
    const payload = data?.data || {}
    const p = payload.pdp || {}
    const skills: any[] = payload.skills || []
    editTemplate.value = {
      title: String(p.title || data.title || ''),
      description: p.description || data.description || '',
      skills: skills.map((s, idx) => {
        let criteriaText = ''
        try {
          const items = s.criteria ? JSON.parse(s.criteria) : []
          if (Array.isArray(items)) {
            criteriaText = items.map((it: any) => (it?.text ?? '')).filter(Boolean).join('\n')
          }
        } catch {}
        return {
          key: s.key,
          skill: s.skill || `Skill ${idx + 1}`,
          description: s.description || '',
          criteriaText,
          order_column: typeof s.order_column === 'number' ? s.order_column : idx,
        }
      })
    }
    editingKey.value = key
    showEditModal.value = true
  } catch (e: any) {
    notifyError('Failed to load template for edit: ' + (e?.message || 'Error'))
  }
}

// Delete template
async function onDeleteTemplate(key: string) {
  const ok = window.confirm('Видалити цей шаблон?\n\nБуде також видалено пов’язані скіли у всіх PDP (окрім тих, що були змінені вручну). Це дію не можна скасувати.')
  if (!ok) return
  try {
    await http(`/pdps/templates/${encodeURIComponent(key)}.json`, { method: 'DELETE' })
    notifySuccess('Template deleted')
    await loadTemplates()
  } catch (e: any) {
    notifyError('Failed to delete template: ' + (e?.message || 'Error'))
  }
}

function addEditSkillRow() { editTemplate.value.skills.push({ skill: '', description: '', criteriaText: '' }) }
function removeEditSkillRow(i: number) { editTemplate.value.skills.splice(i, 1) }

async function saveEdit() {
  if (!editingKey.value) return
  const title = editTemplate.value.title.trim()
  if (!title) { notifyError('Please enter template title'); return }
  const skillsPayload = editTemplate.value.skills.map((s, idx) => {
    const criteriaItems = (s.criteriaText || '')
      .split(/\n+/)
      .map(t => t.trim())
      .filter(Boolean)
      .map(t => ({ text: t }))
    return {
      key: s.key,
      skill: s.skill || `Skill ${idx + 1}`,
      description: s.description || null,
      criteria: criteriaItems.length ? JSON.stringify(criteriaItems) : null,
      priority: 'Medium',
      eta: null,
      status: 'Planned',
      order_column: typeof s.order_column === 'number' ? s.order_column : idx,
    }
  })
  const payload = {
    version: 1,
    pdp: { title, description: editTemplate.value.description || '', priority: 'Medium', eta: null, status: 'Planned' },
    skills: skillsPayload,
  }
  try {
    await http(`/pdps/templates/${encodeURIComponent(editingKey.value)}.json`, { method: 'PUT', body: JSON.stringify(payload) })
    notifySuccess('Template updated')
    showEditModal.value = false
    editingKey.value = null
    await loadTemplates()
  } catch (e: any) {
    notifyError('Failed to update template: ' + (e?.message || 'Error'))
  }
}

const xsrf = () => {
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
  if (!res.ok) {
    const msg = await res.text()
    throw new Error(msg || `Request failed: ${res.status}`)
  }
  if (res.status === 204) return null
  return res.json()
}

async function loadTemplates() {
  loading.value = true
  try {
    templates.value = await http('/pdps/templates.json')
  } catch (e: any) {
    notifyError('Failed to load templates: ' + (e?.message || 'Error'))
  } finally {
    loading.value = false
  }
}

async function assign(key: string) {
  // Open Assign-to-PDP modal instead of creating a new PDP
  await openAssignModal(key)
}


onMounted(loadTemplates)
</script>

<template>
  <Head title="Skill Templates" />
  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
      <Heading title="Skill Templates" description="Каталог шаблонів скілів. Додавайте потрібні скіли у свої PDP, редагуйте або видаляйте шаблони." />

      <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
        <div class="mb-3 flex items-center justify-between">
          <h2 class="text-base font-semibold">Skill Templates</h2>
          <div class="flex items-center gap-2">
            <button class="rounded-md bg-primary px-3 py-2 text-xs font-medium text-primary-foreground hover:opacity-90" @click="openCreate">+ Create Skill Template</button>
          </div>
        </div>

        <div v-if="loading" class="text-sm text-muted-foreground">Loading…</div>
        <div v-else>
          <div v-if="templates.length" class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div v-for="t in templates" :key="t.key" class="rounded-md border p-3">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="font-medium">{{ t.title }}</div>
                  <div v-if="t.description" class="text-xs text-muted-foreground mt-0.5">{{ t.description }}</div>
                  <div class="text-[11px] text-muted-foreground mt-1">{{ t.skills_count }} skills · {{ t.priority }} · {{ t.status }}</div>
                </div>
                <div class="flex gap-2">
                  <button class="rounded-md border px-3 py-1.5 text-xs hover:bg-muted" @click="openEdit(t.key)">Edit</button>
                  <button class="rounded-md border px-3 py-1.5 text-xs text-destructive hover:bg-destructive hover:text-destructive-foreground" @click="onDeleteTemplate(t.key)">Delete</button>
                  <button class="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60" :disabled="assigning===t.key" @click="assign(t.key)">Add to my PDP</button>
                </div>
              </div>
            </div>
          </div>
          <p v-else class="text-sm text-muted-foreground">No templates yet.</p>
        </div>
      </div>

      <!-- Create Template Modal -->
      <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-3xl rounded-xl border border-border bg-background p-4 shadow-xl max-h-[85vh] overflow-y-auto">
          <div class="mb-3 flex items-center justify-between">
            <h3 class="text-base font-semibold">Create Skill Template</h3>
            <button class="rounded p-1 text-muted-foreground hover:bg-muted" @click="showCreateModal=false">✕</button>
          </div>

          <div class="space-y-3">
            <div>
              <label class="mb-1 block text-xs font-medium">Template title</label>
              <input v-model="newTemplate.title" type="text" class="w-full rounded-md border px-3 py-2 text-sm" placeholder="e.g. Frontend Intern PDP" />
            </div>
            <div>
              <label class="mb-1 block text-xs font-medium">Description</label>
              <textarea v-model="newTemplate.description" rows="2" class="w-full rounded-md border px-3 py-2 text-sm" placeholder="Optional short description"></textarea>
            </div>

            <div>
              <div class="mb-2 flex items-center justify-between">
                <label class="block text-xs font-semibold">Skill items</label>
                <button class="rounded-md border px-2 py-1 text-xs hover:bg-muted" @click="addSkillRow">+ Add skill</button>
              </div>
              <div v-if="newTemplate.skills.length===0" class="text-xs text-muted-foreground">No skills yet. Add the first one.</div>
              <div v-for="(s, i) in newTemplate.skills" :key="i" class="mb-3 rounded-md border p-3">
                <div class="mb-2 grid grid-cols-1 gap-2 md:grid-cols-2">
                  <div>
                    <label class="mb-1 block text-xs font-medium">Skill</label>
                    <input v-model="s.skill" type="text" class="w-full rounded-md border px-3 py-2 text-sm" placeholder="e.g. Vue Basics" />
                  </div>
                  <div>
                    <label class="mb-1 block text-xs font-medium">Description</label>
                    <input v-model="s.description" type="text" class="w-full rounded-md border px-3 py-2 text-sm" placeholder="Optional" />
                  </div>
                </div>
                <div>
                  <label class="mb-1 block text-xs font-medium">Win Criteria (one per line)</label>
                  <textarea v-model="s.criteriaText" rows="3" class="w-full rounded-md border px-3 py-2 text-sm" placeholder="Explain tasks or acceptance criteria, each on a new line"></textarea>
                </div>
                <div class="mt-2 flex justify-end">
                  <button class="rounded-md border px-2 py-1 text-xs text-destructive hover:bg-destructive hover:text-destructive-foreground" @click="removeSkillRow(i)">Remove</button>
                </div>
              </div>
            </div>

            <div class="mt-2 flex justify-end gap-2">
              <button class="rounded-md border px-3 py-2 text-xs hover:bg-muted" @click="showCreateModal=false">Cancel</button>
              <button class="rounded-md bg-primary px-3 py-2 text-xs font-medium text-primary-foreground hover:opacity-90" @click="createTemplate">Create</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Edit Template Modal -->
      <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-3xl rounded-xl border border-border bg-background p-4 shadow-xl max-h-[85vh] overflow-y-auto">
          <div class="mb-3 flex items-center justify-between">
            <h3 class="text-base font-semibold">Edit Skill Template</h3>
            <button class="rounded p-1 text-muted-foreground hover:bg-muted" @click="showEditModal=false">✕</button>
          </div>

          <div class="space-y-3">
            <div>
              <label class="mb-1 block text-xs font-medium">Template title</label>
              <input v-model="editTemplate.title" type="text" class="w-full rounded-md border px-3 py-2 text-sm" placeholder="Template title" />
            </div>
            <div>
              <label class="mb-1 block text-xs font-medium">Description</label>
              <textarea v-model="editTemplate.description" rows="2" class="w-full rounded-md border px-3 py-2 text-sm" placeholder="Optional short description"></textarea>
            </div>

            <div>
              <div class="mb-2 flex items-center justify-between">
                <label class="block text-xs font-semibold">Skill items</label>
                <button class="rounded-md border px-2 py-1 text-xs hover:bg-muted" @click="addEditSkillRow">+ Add skill</button>
              </div>
              <div v-if="editTemplate.skills.length===0" class="text-xs text-muted-foreground">No skills yet. Add the first one.</div>
              <div v-for="(s, i) in editTemplate.skills" :key="s.key || i" class="mb-3 rounded-md border p-3">
                <div class="mb-2 grid grid-cols-1 gap-2 md:grid-cols-2">
                  <div>
                    <label class="mb-1 block text-xs font-medium">Skill</label>
                    <input v-model="s.skill" type="text" class="w-full rounded-md border px-3 py-2 text-sm" placeholder="e.g. Vue Basics" />
                  </div>
                  <div>
                    <label class="mb-1 block text-xs font-medium">Description</label>
                    <input v-model="s.description" type="text" class="w-full rounded-md border px-3 py-2 text-sm" placeholder="Optional" />
                  </div>
                </div>
                <div>
                  <label class="mb-1 block text-xs font-medium">Win Criteria (one per line)</label>
                  <textarea v-model="s.criteriaText" rows="3" class="w-full rounded-md border px-3 py-2 text-sm" placeholder="Explain tasks or acceptance criteria, each on a new line"></textarea>
                </div>
                <div class="mt-2 flex justify-between items-center gap-2">
                  <div class="text-[11px] text-muted-foreground">Order: <input v-model.number="(s as any).order_column" type="number" min="0" class="w-16 rounded border px-2 py-1 text-xs" /></div>
                  <button class="rounded-md border px-2 py-1 text-xs text-destructive hover:bg-destructive hover:text-destructive-foreground" @click="removeEditSkillRow(i)">Remove</button>
                </div>
              </div>
            </div>

            <div class="mt-2 flex justify-end gap-2">
              <button class="rounded-md border px-3 py-2 text-xs hover:bg-muted" @click="showEditModal=false">Cancel</button>
              <button class="rounded-md bg-primary px-3 py-2 text-xs font-medium text-primary-foreground hover:opacity-90" @click="saveEdit">Save</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Assign Template Skills to PDP Modal -->
      <div v-if="showAssignModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-3xl rounded-xl border border-border bg-background p-4 shadow-xl max-h-[85vh] overflow-y-auto">
          <div class="mb-3 flex items-center justify-between">
            <h3 class="text-base font-semibold">Add template skills to PDP</h3>
            <button class="rounded p-1 text-muted-foreground hover:bg-muted" @click="closeAssignModal">✕</button>
          </div>

          <div class="space-y-3">
            <div>
              <label class="mb-1 block text-xs font-medium">Target PDP</label>
              <select v-model.number="selectedPdpId" class="w-full rounded-md border px-3 py-2 text-sm" :disabled="assignLoading">
                <option :value="0" disabled>Select your PDP</option>
                <option v-for="p in pdpOptions" :key="p.id" :value="p.id">
                  {{ p.title }} · {{ p.status }} · {{ p.skills_count ?? 0 }} skills
                </option>
              </select>
              <p v-if="assignLoading" class="mt-1 text-xs text-muted-foreground">Loading your PDPs…</p>
              <p v-else-if="pdpOptions.length===0" class="mt-1 text-xs text-muted-foreground">
                You have no editable PDPs (only non-completed are available).
              </p>
            </div>
            <p class="text-xs text-muted-foreground">All skills from the selected template will be added to the chosen PDP. Existing skills from this template will be skipped.</p>

            <div class="mt-2 flex justify-end gap-2">
              <button class="rounded-md border px-3 py-2 text-xs hover:bg-muted" @click="closeAssignModal">Cancel</button>
              <button class="rounded-md bg-primary px-3 py-2 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60" :disabled="!canSubmitAssign" @click="submitAssign">Add to PDP</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
