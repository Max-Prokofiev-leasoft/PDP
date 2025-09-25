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

const breadcrumbs = computed(() => [{ title: 'PDP Templates', href: '/pdps/templates' }])


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
  assigning.value = key
  try {
    await http(`/pdps/templates/${encodeURIComponent(key)}/assign.json`, { method: 'POST', body: '{}' })
    notifySuccess('Template has been added to Your PDPs')
    // Redirect to PDP list so user can see the newly added PDP
    router.visit('/pdps')
  } catch (e: any) {
    notifyError('Failed to add template: ' + (e?.message || 'Error'))
  } finally {
    assigning.value = null
  }
}


onMounted(loadTemplates)
</script>

<template>
  <Head title="PDP Templates" />
  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
      <Heading title="PDP Templates" description="A collection of ready-to-use PDP templates. Add any template to your own PDP list." />

      <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
        <div class="mb-3 flex items-center justify-between">
          <h2 class="text-base font-semibold">Templates</h2>
          <div class="flex items-center gap-2">
            <button class="rounded-md bg-primary px-3 py-2 text-xs font-medium text-primary-foreground hover:opacity-90" @click="openCreate">+ Create Template</button>
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
                <button class="rounded-md bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground hover:opacity-90 disabled:opacity-60" :disabled="assigning===t.key" @click="assign(t.key)">Add to me</button>
              </div>
            </div>
          </div>
          <p v-else class="text-sm text-muted-foreground">No templates yet.</p>
        </div>
      </div>

      <!-- Create Template Modal -->
      <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-3xl rounded-xl border border-border bg-background p-4 shadow-xl">
          <div class="mb-3 flex items-center justify-between">
            <h3 class="text-base font-semibold">Create Template</h3>
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
                <label class="block text-xs font-semibold">Skills</label>
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
    </div>
  </AppLayout>
</template>
