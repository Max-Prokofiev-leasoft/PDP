<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head } from '@inertiajs/vue3'
import Heading from '@/components/Heading.vue'
import PdpFormModal from '@/components/pdps/modals/PdpFormModal.vue'
import SkillFormModal from '@/components/pdps/modals/SkillFormModal.vue'
import { getJsPdfCtor } from '@/composables/usePdfExport'
import { ensurePdfUnicodeFont } from '@/utils/pdfFont'
import { getLeaSoftLogoCircular } from '@/utils/images'
import { formatKyivDateTime } from '@/utils/date'
import { parseCriteriaItems } from '@/utils/criteria'
import { statusBadgeClass } from '@/utils/status'
import { notifySuccess, notifyError } from '@/composables/useNotify'
import { confirmDialog } from '@/composables/useConfirm'





// Types
export type Pdp = {
  id: number
  title: string
  description?: string
  priority: 'Low' | 'Medium' | 'High'
  eta?: string
  status: 'Planned' | 'In Progress' | 'Done' | 'Blocked'
  skills_count?: number
  user?: { id: number; name?: string; email: string }
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

interface Curator { id: number; name?: string; email: string }

const breadcrumbsItems = computed<BreadcrumbItem[]>(() => [
  { title: activeTab.value === 'Annex' ? 'Annex' : 'PDP List', href: activeTab.value === 'Annex' ? '/pdps?tab=annex' : '/pdps' },
])

// State
const pdps = ref<Pdp[]>([])
const sharedPdps = ref<Pdp[]>([])
const selectedPdpId = ref<number | null>(null)
const skills = ref<PdpSkill[]>([])
const curators = ref<Curator[]>([])

// Collapsible sections state
const collapseOwned = ref(false)
const collapseShared = ref(false)

try {
  // Restore from localStorage
  const co = localStorage.getItem('pdp.collapseOwned')
  const cs = localStorage.getItem('pdp.collapseShared')
  collapseOwned.value = co === '1'
  collapseShared.value = cs === '1'
} catch {}

watch(collapseOwned, v => { try { localStorage.setItem('pdp.collapseOwned', v ? '1' : '0') } catch {} })
watch(collapseShared, v => { try { localStorage.setItem('pdp.collapseShared', v ? '1' : '0') } catch {} })

// Tabs
const activeTab = ref<'Manage' | 'Annex'>('Manage')

// Annex state
const annex = ref<any | null>(null)

const showPdpModal = ref(false)
const editingPdpId = ref<number | null>(null)
const pdpForm = reactive<Pdp>({ id: 0, title: '', description: '', priority: 'Medium', eta: '', status: 'Planned' })

const showSkillModal = ref(false)
const editingSkillId = ref<number | null>(null)
const skillForm = reactive<PdpSkill>({ id: 0, pdp_id: 0, skill: '', description: '', criteria: '', priority: 'Medium', eta: '', status: 'Planned' })

// Win Criteria helpers with per-item comments (stored as JSON in criteria string with legacy fallback)
interface CriteriaItem { text: string; comment?: string; done?: boolean }
const criteriaTextInput = ref('')
const criteriaCommentInput = ref('')
const criteriaItems = ref<CriteriaItem[]>([])


function resetCriteriaState(fromText: string = '') {
  criteriaItems.value = parseCriteriaItems(fromText)
  criteriaTextInput.value = ''
  criteriaCommentInput.value = ''
}

function addCriteriaFromInput() {
  const text = criteriaTextInput.value.trim()
  const comment = criteriaCommentInput.value.trim()
  if (!text) return
  criteriaItems.value.push({ text, comment: comment || undefined })
  criteriaTextInput.value = ''
  criteriaCommentInput.value = ''
}

function removeCriteriaAt(index: number) {
  criteriaItems.value.splice(index, 1)
}

function updateCriteriaAt(index: number, text: string) {
  if (index >= 0 && index < criteriaItems.value.length) {
    criteriaItems.value[index].text = text
  }
}

function onPdpModalSave(payload: Pdp) {
  Object.assign(pdpForm, payload)
  savePdp()
}

function onSkillModalSave(payload: PdpSkill) {
  Object.assign(skillForm, payload)
  saveSkill()
}

// Progress modal state
const showProgressModal = ref(false)
const progressState = reactive<{ pdp_id: number; skill_id: number; index: number; text: string; entries: Array<{id:number; note:string; approved?: boolean; created_at:string; user?:{id:number; name:string; email:string}}>; newNote: string; loading: boolean }>({ pdp_id: 0, skill_id: 0, index: 0, text: '', entries: [], newNote: '', loading: false })

async function openProgressModal(s: PdpSkill, index: number) {
  const items = parseCriteriaItems(s.criteria)
  const current = items[index]
  progressState.pdp_id = s.pdp_id
  progressState.skill_id = s.id
  progressState.index = index
  progressState.text = current?.text || ''
  progressState.entries = []
  progressState.newNote = ''
  showProgressModal.value = true
  await loadProgressEntries()
}

async function loadProgressEntries() {
  progressState.loading = true
  try {
    const data = await http(`/pdps/${progressState.pdp_id}/skills/${progressState.skill_id}/criteria/${progressState.index}/progress.json`)
    progressState.entries = data.entries || []
    // keep text from server in case updated
    if (data.criterion?.text) progressState.text = data.criterion.text
  } catch (e: any) {
    notifyError('Failed to load progress: ' + (e?.message || 'Error'))
  } finally {
    progressState.loading = false
  }
}

async function addProgressNote() {
  const note = progressState.newNote.trim()
  if (!note) return
  try {
    await http(`/pdps/${progressState.pdp_id}/skills/${progressState.skill_id}/criteria/${progressState.index}/progress.json`, {
      method: 'POST',
      body: JSON.stringify({ note })
    })
    progressState.newNote = ''
    await loadProgressEntries()
  } catch (e: any) {
    notifyError('Failed to add progress entry: ' + (e?.message || 'Error'))
  }
}

async function deleteProgressEntry(id: number) {
  const ok = await confirmDialog('Delete this progress entry?')
  if (!ok) return
  try {
    await http(`/pdps/${progressState.pdp_id}/skills/${progressState.skill_id}/criteria/${progressState.index}/progress/${id}.json`, {
      method: 'DELETE'
    })
    await loadProgressEntries()
  } catch (e: any) {
    notifyError('Failed to delete entry: ' + (e?.message || 'Error'))
  }
}

async function toggleCriterionDone(s: PdpSkill, index: number, done: boolean) {
  try {
    await http(`/pdps/${s.pdp_id}/skills/${s.id}/criteria/${index}/done.json`, {
      method: 'PATCH',
      body: JSON.stringify({ done })
    })
    if (selectedPdpId.value) await loadSkills(selectedPdpId.value)
  } catch (e: any) {
    notifyError('Failed to update criterion state: ' + (e?.message || 'Error'))
  }
}

async function approveProgressEntry(id: number) {
  try {
    await http(`/pdps/${progressState.pdp_id}/skills/${progressState.skill_id}/criteria/${progressState.index}/progress/${id}/approve.json`, {
      method: 'POST'
    })
    await loadProgressEntries()
  } catch (e: any) {
    notifyError('Failed to approve entry: ' + (e?.message || 'Error'))
  }
}

const xsrf = () => {
  try {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
    return m ? decodeURIComponent(m[1]) : ''
  } catch {
    return ''
  }
}

// Helpers
async function http(url: string, options: RequestInit = {}) {
  const isGet = !options.method || options.method.toUpperCase() === 'GET'
  const headers: HeadersInit = {
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    // Rely solely on X-XSRF-TOKEN (cookie-based) to avoid stale meta token mismatches
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

// Loaders
async function loadPdps() {
  pdps.value = await http('/pdps.json')
}

async function loadSharedPdps() {
  try {
    sharedPdps.value = await http('/pdps.shared.json')
  } catch {
    sharedPdps.value = []
  }
}

async function loadSkills(pdpId: number) {
  skills.value = await http(`/pdps/${pdpId}/skills.json`)
}

async function loadCurators(pdpId: number) {
  try {
    curators.value = await http(`/pdps/${pdpId}/curators.json`)
  } catch {
    curators.value = []
  }
}

async function loadAnnex(pdpId: number) {
  annex.value = await http(`/pdps/${pdpId}/annex.json`)
}



function filenameSafe(input: string): string {
  return (input || 'PDP').replace(/[^\w\-\s]+/g, '').replace(/\s+/g, '_').slice(0, 60)
}

async function downloadPdpTemplate() {
  if (!selectedPdpId.value || !selectedPdp.value) return
  try {
    const data = await http(`/pdps/${selectedPdpId.value}/export.json`)
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    const name = `PDP_Template_${filenameSafe(selectedPdp.value.title)}.json`
    a.href = url
    a.download = name
    document.body.appendChild(a)
    a.click()
    URL.revokeObjectURL(url)
    a.remove()
  } catch (e: any) {
    alert('Failed to export PDP: ' + (e?.message || 'Error'))
  }
}

// Build a PDF document for the current Annex data (using jsPDF)
async function buildAnnexPdf(JsPDFCtor: any, data: any) {
  const doc = new JsPDFCtor({ unit: 'pt', format: 'a4' })
  await ensurePdfUnicodeFont(doc)
  const margin = 48
  const pageWidth = doc.internal.pageSize.getWidth()
  const pageHeight = doc.internal.pageSize.getHeight()
  const contentWidth = pageWidth - margin * 2
  let y = margin

  function ensureSpace(height: number) {
    if (y + height > pageHeight - margin) {
      doc.addPage()
      // ensure font persists across pages
      try { doc.setFont(_pdfFontName, _pdfFontStyle) } catch {}
      y = margin
    }
  }

  function setFontSafe(bold = false) {
    try {
      // Try to use our Unicode font for all text. Bold may not be available, so fall back to normal.
      doc.setFont(_pdfFontName, bold ? 'bold' : _pdfFontStyle)
    } catch {
      try { doc.setFont(_pdfFontName, _pdfFontStyle) } catch {}
    }
  }

  function addText(text: string, size = 12, bold = false, extraSpacing = 6) {
    if (text == null) return
    setFontSafe(bold)
    doc.setFontSize(size)
    const lines = doc.splitTextToSize(String(text), contentWidth)
    const lineHeight = size * 1.2
    ensureSpace(lineHeight * lines.length + extraSpacing)
    doc.text(lines, margin, y)
    y += lineHeight * lines.length + extraSpacing
  }

  function addHeading(text: string, level: 1) {
    const size = level === 1 ? 18 : 14
    addText(text, size, true, 10)
  }

  function addSpacer(h = 8) {
    ensureSpace(h)
    y += h
  }

  if (!data) return doc
  const pdp = data.pdp || {}

  // Modern header with logo, owner, curators
  const headerHeight = 70
  ensureSpace(headerHeight)
  // Company logo from public/images (fallback to simple circle if missing)
  try {
    const logo = await getLeaSoftLogoCircular(0)
    if (logo) {
      // Draw the circular logo 40x40 at the left; keep title offset at +48 to align
      doc.addImage(logo, 'PNG', margin, y, 40, 40)
    } else {
      doc.setFillColor(28, 100, 242)
      doc.circle(margin + 20, y + 20, 14, 'F')
      setFontSafe(true)
      doc.setTextColor(255, 255, 255)
      doc.setFontSize(12)
      doc.text('P', margin + 20 - 3.5, y + 24)
      doc.setTextColor(0, 0, 0)
    }
  } catch {
    try {
      doc.setFillColor(28, 100, 242)
      doc.circle(margin + 20, y + 20, 14, 'F')
      setFontSafe(true)
      doc.setTextColor(255, 255, 255)
      doc.setFontSize(12)
      doc.text('P', margin + 20 - 3.5, y + 24)
      doc.setTextColor(0, 0, 0)
    } catch {}
  }

  // Title
  setFontSafe(true)
  doc.setFontSize(18)
  doc.text(`Annex — ${pdp.title || 'PDP'}`, margin + 48, y + 8)

  // Meta + owner/curators under title
  setFontSafe(false)
  doc.setFontSize(11)
  const meta: string[] = []
  if (pdp.status) meta.push(`Status: ${pdp.status}`)
  if (pdp.priority) meta.push(`Priority: ${pdp.priority}`)
  if (pdp.eta) meta.push(`ETA: ${pdp.eta}`)
  const owner = (data && data.owner) || (selectedPdp?.value as any)?.user || null
  const ownerLine = owner ? `Owner: ${owner.name || owner.email || '—'}` : (selectedPdpIsOwner.value ? 'Owner: You' : '')
  const curatorsList = Array.isArray((data && data.curators)) ? (data.curators as any[]) : curators.value
  const curatorsLine = curatorsList && curatorsList.length ? `Curators: ${curatorsList.map((c:any)=>c.name || c.email).join(', ')}` : ''
  const metaLine = meta.join(' · ')
  const infoCombined = [metaLine, ownerLine, curatorsLine].filter(Boolean).join('  |  ')
  const lines = doc.splitTextToSize(infoCombined, contentWidth - 48)
  let infoY = y + 26
  for (const ln of lines) {
    ensureSpace(14)
    doc.text(ln, margin + 48, infoY)
    infoY += 14
  }
  y = infoY + 6
  if (pdp.description) addText(String(pdp.description))
  addSpacer(6)

  const skills = Array.isArray(data.skills) ? data.skills : []
  if (!skills.length) {
    addText('There are no skills in this PDP.')
    return doc
  }

  for (const s of skills) {
    addHeading(s.skill || 'Skill', 2)
    if (s.description) addText(String(s.description))

    const crit = Array.isArray(s.criteria) ? s.criteria : []
    let anyEntries = false
    for (const c of crit) {
      const entries = Array.isArray(c.entries) ? c.entries : []
      if (!entries.length) continue
      anyEntries = true
      // Criterion line
      addText(`• ${String(c.text || '')}`, 12, true, 4)
      for (const e of entries) {
        const when = formatKyivDateTime(e.created_at)
        const author = e.user?.name ? ` · ${e.user.name}` : ''
        addText(`${when}${author}`, 10, false, 2)
        if (e.note) addText(String(e.note), 11, false, 6)
      }
    }
    if (!anyEntries) addText('No approved entries.', 12, false, 6)
    addSpacer(6)
  }

  return doc
}

async function downloadCurrentPdpAnnex() {
  if (!selectedPdp.value) return
  if (!annex.value && selectedPdpId.value) {
    await loadAnnex(selectedPdpId.value)
  }
  const JsPDFCtor = await getJsPdfCtor()
  // Ensure curators loaded for owner case
  if (selectedPdpIsOwner.value && selectedPdpId.value && curators.value.length === 0) {
    try { await loadCurators(selectedPdpId.value) } catch {}
  }
  const payload = { ...(annex.value || {}), owner: (selectedPdp.value as any)?.user || (selectedPdpIsOwner.value ? { name: 'You' } : null), curators: curators.value }
  const doc = await buildAnnexPdf(JsPDFCtor, payload)
  const now = new Date()
  const yyyy = now.getFullYear()
  const mm = String(now.getMonth()+1).padStart(2,'0')
  const dd = String(now.getDate()).padStart(2,'0')
  const hh = String(now.getHours()).padStart(2,'0')
  const mi = String(now.getMinutes()).padStart(2,'0')
  const name = `Annex_${filenameSafe(selectedPdp.value.title)}_${yyyy}-${mm}-${dd}_${hh}-${mi}.pdf`
  doc.save(name)
}

function selectPdp(id: number) {
  selectedPdpId.value = id
  // Load curators only for owned PDPs
  if (pdps.value.some(p => p.id === id)) {
    loadCurators(id)
  } else {
    curators.value = []
  }
  if (activeTab.value === 'Manage') {
    loadSkills(id)
  } else {
    loadAnnex(id)
  }
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
  if (!pdpForm.title.trim()) { notifyError('Please enter PDP title'); return }
  const body = JSON.stringify({ title: pdpForm.title, description: pdpForm.description, priority: pdpForm.priority, eta: pdpForm.eta, status: pdpForm.status })
  if (editingPdpId.value) {
    await http(`/pdps/${editingPdpId.value}.json`, { method: 'PUT', body })
  } else {
    const created = await http('/pdps.json', { method: 'POST', body })
    selectedPdpId.value = created.id
  }
  showPdpModal.value = false
  await loadPdps()
  if (selectedPdpId.value) {
    if (activeTab.value === 'Manage') {
      await loadSkills(selectedPdpId.value)
    } else {
      await loadAnnex(selectedPdpId.value)
    }
  }
}

async function deletePdp(id: number) {
  { const ok = await confirmDialog('Delete this PDP and all its skills?'); if (!ok) return }
  await http(`/pdps/${id}.json`, { method: 'DELETE' })
  if (selectedPdpId.value === id) selectedPdpId.value = null
  await loadPdps()
}

// Skills modal
function openCreateSkill() {
  if (!selectedPdpId.value) return
  editingSkillId.value = null
  Object.assign(skillForm, { id: 0, pdp_id: selectedPdpId.value, skill: '', description: '', criteria: '', priority: 'Medium', eta: '', status: 'Planned' })
  resetCriteriaState('')
  showSkillModal.value = true
}

function openEditSkill(s: PdpSkill) {
  editingSkillId.value = s.id
  Object.assign(skillForm, { ...s })
  resetCriteriaState(s.criteria || '')
  showSkillModal.value = true
}

async function saveSkill() {
  if (!selectedPdpId.value) return
  if (!skillForm.skill.trim()) { notifyError('Please fill the "Skill to achieve" field'); return }
  // Serialize as JSON array of { text, comment } (backward compatible parser will read legacy)
  skillForm.criteria = JSON.stringify(criteriaItems.value)
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
  { const ok = await confirmDialog('Delete this skill?'); if (!ok) return }
  await http(`/pdps/${selectedPdpId.value}/skills/${id}.json`, { method: 'DELETE' })
  await loadSkills(selectedPdpId.value)
}

const hasPdps = computed(() => pdps.value.length > 0)
const hasSharedPdps = computed(() => sharedPdps.value.length > 0)
const selectedPdp = computed(() => {
  const id = selectedPdpId.value
  if (!id) return null
  return pdps.value.find(p => p.id === id) || sharedPdps.value.find(p => p.id === id) || null
})
const selectedPdpIsOwner = computed(() => {
  const id = selectedPdpId.value
  if (!id) return false
  return pdps.value.some(p => p.id === id)
})
const selectedPdpIsCurator = computed(() => {
  const id = selectedPdpId.value
  if (!id) return false
  return sharedPdps.value.some(p => p.id === id)
})
const selectedPdpIsEditable = computed(() => selectedPdpIsOwner.value || selectedPdpIsCurator.value)

function selectPdpFromShared(id: number) {
  selectedPdpId.value = id
  curators.value = []
  if (activeTab.value === 'Manage') {
    loadSkills(id)
  } else {
    loadAnnex(id)
  }
}

const curatorEmail = ref('')
const userSearch = ref('')
const userOptions = ref<Curator[]>([])
const showUserDropdown = ref(false)
let userSearchTimer: number | null = null

watch(curatorEmail, (v) => {
  userSearch.value = v
  if (!v || v.length < 1) { userOptions.value = []; showUserDropdown.value = false; return }
  if (userSearchTimer) clearTimeout(userSearchTimer as any)
  userSearchTimer = window.setTimeout(async () => {
    try {
      const data = await http('/users.search.json?q=' + encodeURIComponent(v))
      userOptions.value = Array.isArray(data) ? data : []
      showUserDropdown.value = userOptions.value.length > 0
    } catch {
      userOptions.value = []
      showUserDropdown.value = false
    }
  }, 200)
})

function selectUserOption(u: Curator) {
  curatorEmail.value = u.email
  showUserDropdown.value = false
}

function closeUserDropdown() { showUserDropdown.value = false }

async function assignCurator() {
  const email = curatorEmail.value.trim()
  if (!selectedPdpId.value) return
  if (!email || !email.includes('@')) { notifyError('Enter a valid email'); return }
  try {
    const res = await http(`/pdps/${selectedPdpId.value}/assign-curator.json`, { method: 'POST', body: JSON.stringify({ email }) })
    if (res?.curator) {
      const exists = curators.value.some(c => c.id === res.curator.id)
      if (!exists) curators.value.push(res.curator as Curator)
    }
    notifySuccess('Curator assigned')
    curatorEmail.value = ''
  } catch (e: any) {
    notifyError('Failed to assign curator: ' + (e?.message || 'Error'))
  }
}

async function removeCurator(c: Curator) {
  if (!selectedPdpId.value) return
  { const ok = await confirmDialog(`Remove ${c.name || c.email} from curators?`); if (!ok) return }
  try {
    await http(`/pdps/${selectedPdpId.value}/curators/${c.id}.json`, { method: 'DELETE' })
    curators.value = curators.value.filter(x => x.id !== c.id)
  } catch (e: any) {
    notifyError('Failed to remove curator: ' + (e?.message || 'Error'))
  }
}

onMounted(async () => {
  let deepPdp: number | null = null
  let deepSkill: number | null = null
  let deepCriterion: number | null = null
  try {
    const params = new URLSearchParams(location.search)
    const tab = params.get('tab')?.toLowerCase()
    if (tab === 'annex') activeTab.value = 'Annex'
    const p = parseInt(params.get('pdp') || '', 10)
    const s = parseInt(params.get('skill') || '', 10)
    const c = parseInt(params.get('criterion') || '', 10)
    deepPdp = Number.isFinite(p) && p > 0 ? p : null
    deepSkill = Number.isFinite(s) && s > 0 ? s : null
    deepCriterion = Number.isFinite(c) && c >= 0 ? c : null
    // Force Manage tab if deep-link provided to open progress modal
    if (deepPdp && deepSkill != null && deepCriterion != null) {
      activeTab.value = 'Manage'
    }
  } catch {}
  await Promise.all([loadPdps(), loadSharedPdps()])
  if (deepPdp) {
    selectedPdpId.value = deepPdp
    // Load curators only for owned PDPs
    if (pdps.value.some(p => p.id === deepPdp)) {
      loadCurators(deepPdp)
    } else {
      curators.value = []
    }
    await loadSkills(deepPdp)
    if (deepSkill && deepCriterion != null) {
      const s = skills.value.find(sk => sk.id === deepSkill)
      if (s) {
        openProgressModal(s, deepCriterion)
      }
    }
  }
})

</script>

<template>
  <Head :title="activeTab==='Annex' ? 'Annex' : 'PDP List'" />

  <AppLayout :breadcrumbs="breadcrumbsItems">
    <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
      <Heading :title="activeTab==='Annex' ? 'Annex' : 'PDP List'" :description="activeTab==='Annex' ? 'Annex — a document with approved progress entries.' : 'PDP is a plan that contains a list of skills/tasks to achieve.'" />

      <div class="flex flex-col gap-4">
        <!-- PDP list (top) -->
        <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
          <div class="mb-3 flex items-center justify-between">
            <div class="flex items-center gap-2">
              <h2 class="text-base font-semibold flex items-center gap-2">Your PDPs <span class="inline-flex items-center justify-center rounded-md border px-1.5 py-0.5 text-[10px] leading-none min-w-[18px] text-muted-foreground">{{ pdps.length }}</span></h2>
              <button class="rounded p-1 text-muted-foreground hover:bg-muted transition" @click="collapseOwned=!collapseOwned" :title="collapseOwned ? 'Expand' : 'Collapse'" :aria-label="collapseOwned ? 'Expand' : 'Collapse'">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 transition-transform" :class="collapseOwned ? '-rotate-90' : 'rotate-0'">
                  <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                </svg>
              </button>
            </div>
            <div class="flex items-center gap-2" v-if="activeTab!=='Annex'">
              <button class="rounded-md bg-primary px-3 py-2 text-xs font-medium text-primary-foreground hover:opacity-90" @click="openCreatePdp">+ Add PDP</button>
            </div>
          </div>

          <div v-if="!collapseOwned">
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
            <p v-else class="text-sm text-muted-foreground">The list is empty. Add the first PDP.</p>
          </div>
          <div v-else class="my-2 h-px bg-border"></div>

          <div class="mt-6">
            <div class="mb-3 flex items-center justify-between">
              <div class="flex items-center gap-2">
                <h2 class="text-base font-semibold flex items-center gap-2">Shared PDPs <span class="inline-flex items-center justify-center rounded-md border px-1.5 py-0.5 text-[10px] leading-none min-w-[18px] text-muted-foreground">{{ sharedPdps.length }}</span></h2>
                <button class="rounded p-1 text-muted-foreground hover:bg-muted transition" @click="collapseShared=!collapseShared" :title="collapseShared ? 'Expand' : 'Collapse'" :aria-label="collapseShared ? 'Expand' : 'Collapse'">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 transition-transform" :class="collapseShared ? '-rotate-90' : 'rotate-0'">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                  </svg>
                </button>
              </div>
            </div>
            <div v-if="!collapseShared">
              <div v-if="hasSharedPdps" class="space-y-1">
                <button v-for="p in sharedPdps" :key="'s-'+p.id" class="w-full rounded-md border px-3 py-2 text-left text-sm hover:bg-muted" :class="selectedPdpId===p.id ? 'border-primary' : 'border-border'" @click="selectPdpFromShared(p.id)">
                  <div class="flex items-center justify-between">
                    <span class="font-medium">{{ p.title }}</span>
                    <span class="text-xs text-muted-foreground">{{ p.skills_count ?? 0 }} skills</span>
                  </div>
                  <div class="text-xs text-muted-foreground">{{ p.status }} · {{ p.priority }}<span v-if="p.eta"> · ETA: {{ p.eta }}</span></div>
                  <div v-if="p.user" class="text-[11px] text-muted-foreground mt-0.5">Owner: {{ p.user.name || p.user.email }}<span v-if="p.user.name"> ({{ p.user.email }})</span></div>
                </button>
              </div>
              <p v-else class="text-sm text-muted-foreground">No shared PDPs yet.</p>
            </div>
            <div v-else class="my-2 h-px bg-border"></div>
          </div>
        </div>

        <!-- Tabs: Manage / Annex -->
        <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
          <div class="mb-3 flex items-center justify-end">
            <div v-if="selectedPdp && activeTab==='Manage' && selectedPdpIsEditable" class="flex gap-2">
              <button class="rounded-md border px-3 py-2 text-xs hover:bg-muted" @click="openEditPdp(selectedPdp as any)">Edit PDP</button>
              <button class="rounded-md border px-3 py-2 text-xs hover:bg-muted" @click="downloadPdpTemplate">Export JSON</button>
              <button class="rounded-md bg-primary px-3 py-2 text-xs font-medium text-primary-foreground hover:opacity-90" @click="openCreateSkill">+ Add Skill</button>
            </div>
            <div v-if="selectedPdp && activeTab==='Annex' && (annex?.skills || []).length">
              <button class="rounded-md bg-primary px-3 py-2 text-xs font-medium text-primary-foreground hover:opacity-90" @click="downloadCurrentPdpAnnex">Download current PDP</button>
            </div>
          </div>

          <!-- Manage tab content -->
          <template v-if="activeTab==='Manage'">
            <template v-if="selectedPdp">
              <h3 class="mb-1 text-lg font-semibold">{{ selectedPdp.title }}</h3>
              <p class="mb-3 text-sm text-muted-foreground">{{ selectedPdp.description }}</p>
              <p v-if="selectedPdpIsCurator && (selectedPdp as any)?.user" class="-mt-2 mb-3 text-[11px] text-muted-foreground">Owner: {{ (selectedPdp as any).user.name || (selectedPdp as any).user.email }}<span v-if="(selectedPdp as any).user.name"> ({{ (selectedPdp as any).user.email }})</span></p>

              <div v-if="activeTab==='Manage' && selectedPdpIsOwner" class="mb-4">
                <div class="flex items-center gap-2">
                  <div class="relative">
                    <input v-model="curatorEmail" @focus="showUserDropdown = userOptions.length>0" @blur="setTimeout(()=>closeUserDropdown(),100)" type="text" placeholder="Enter curator email or name" class="w-64 rounded border px-2 py-1 text-sm" />
                    <ul v-if="showUserDropdown" class="absolute z-10 mt-1 max-h-56 w-[22rem] overflow-auto rounded-md border bg-background shadow">
                      <li v-for="u in userOptions" :key="u.id" class="flex cursor-pointer items-center justify-between px-2 py-1 text-sm hover:bg-muted" @mousedown.prevent="selectUserOption(u)">
                        <span class="font-medium">{{ u.name || u.email }}</span>
                        <span class="ml-2 text-xs text-muted-foreground" v-if="u.name">{{ u.email }}</span>
                      </li>
                      <li v-if="!userOptions.length" class="px-2 py-1 text-xs text-muted-foreground">No matches</li>
                    </ul>
                  </div>
                  <button class="rounded-md border px-3 py-1.5 text-xs hover:bg-muted" @click="assignCurator">Assign curator</button>
                </div>
                <div v-if="curators.length" class="mt-2 flex flex-wrap gap-2">
                  <span v-for="c in curators" :key="c.id" class="inline-flex items-center gap-2 rounded-md border px-2 py-0.5 text-xs">
                    <span class="font-medium">{{ c.name || c.email }}</span>
                    <span v-if="c.name" class="text-muted-foreground">{{ c.email }}</span>
                    <button class="ml-1 inline-flex h-5 w-5 items-center justify-center rounded-md border text-[11px] hover:bg-muted" title="Remove curator" @click="removeCurator(c)">×</button>
                  </span>
                </div>
              </div>

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
                        <td class="px-3 py-3">
                            <div v-if="parseCriteriaItems(s.criteria).length" class="flex flex-col gap-1.5">
                                <div v-for="(c, i) in parseCriteriaItems(s.criteria)" :key="i" class="flex items-start gap-1 w-full">
                                    <button type="button" class="inline-flex flex-1 items-start justify-between rounded-md border border-border bg-muted px-2 py-1 text-xs hover:bg-muted/70 cursor-pointer text-left" :title="'Click to add/view progress'" @click="openProgressModal(s, i)">
                                        <span class="whitespace-normal break-words">{{ c.text }}</span>
                                        <span v-if="c.comment" class="ml-2 shrink-0 text-muted-foreground">•</span>
                                    </button>
                                    <button v-if="selectedPdpIsEditable" type="button" class="inline-flex h-[22px] w-[22px] flex-none items-center justify-center rounded-md border text-[10px] hover:bg-muted" :title="c.done ? 'Mark as not done' : 'Mark as done'" @click.stop="toggleCriterionDone(s, i, !c.done)">
                                        <svg v-if="!c.done" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3">
                                            <path fill-rule="evenodd" d="M3.5 10a6.5 6.5 0 1113 0 6.5 6.5 0 01-13 0zm9.204-2.79a1 1 0 10-1.414-1.414L8.5 8.586 7.21 7.296a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l3.494-3.5z" clip-rule="evenodd" />
                                        </svg>
                                        <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3 text-green-600">
                                            <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.414l-7.5 7.5a1 1 0 01-1.414 0l-3-3a1 1 0 111.414-1.414l2.293 2.293 6.793-6.793a1 1 0 011.414 0z" clip-rule="evenodd" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                      <td class="px-3 py-3">{{ s.priority }}</td>
                      <td class="px-3 py-3">{{ s.eta }}</td>
                      <td class="px-3 py-3">
                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] whitespace-nowrap" :class="statusBadgeClass(s.status)">
                          {{ s.status }}
                        </span>
                      </td>
                      <td class="px-3 py-3 text-right">
                        <div class="flex justify-end gap-2" v-if="selectedPdpIsEditable">
                          <button class="rounded border px-2 py-1 text-xs hover:bg-muted" @click="openEditSkill(s)">Edit</button>
                          <button class="rounded border px-2 py-1 text-xs text-destructive hover:bg-destructive hover:text-destructive-foreground" @click="deleteSkill(s.id)">Delete</button>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p v-else class="text-sm text-muted-foreground">No skills in this PDP. Add the first one.</p>
            </template>
            <p v-else class="text-sm text-muted-foreground">Select a PDP to view its skills.</p>
          </template>

          <!-- Annex tab content -->
          <template v-else>
            <template v-if="selectedPdp">
              <h3 class="mb-3 text-lg font-semibold">{{ selectedPdp.title }}</h3>
              <div v-if="(annex?.skills || []).length" class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <!-- Sidebar: skills list -->
                <div class="md:col-span-1 border rounded-md p-2 max-h-[60vh] overflow-auto">
                  <h3 class="mb-2 text-xs font-semibold text-muted-foreground">Skills</h3>
                  <ul class="space-y-1 text-sm">
                    <li v-for="s in (annex?.skills || [])" :key="s.id">
                      <a class="block rounded px-2 py-1 hover:bg-muted" :href="'#skill-' + s.id">{{ s.skill }}</a>
                    </li>
                  </ul>
                </div>
                <!-- Document body -->
                <div class="md:col-span-3 space-y-6">
                  <div v-for="s in (annex?.skills || [])" :key="s.id" :id="'skill-' + s.id" class="rounded-md border">
                    <div class="border-b bg-muted/50 px-3 py-2">
                      <h4 class="font-semibold">{{ s.skill }}</h4>
                      <div class="text-xs text-muted-foreground">{{ s.description }}</div>
                    </div>
                    <div class="p-3 space-y-4">
                      <template v-for="c in s.criteria" :key="c.index">
                        <div v-if="c.entries && c.entries.length">
                          <div class="font-medium">• {{ c.text }}</div>
                          <div class="mt-1 space-y-2">
                            <div v-for="e in c.entries" :key="e.id" class="rounded-md bg-muted/40 px-3 py-2 text-sm">
                              <div class="mb-1 text-[11px] text-muted-foreground">{{ formatKyivDateTime(e.created_at) }}<span v-if="e.user"> · {{ e.user.name }}</span></div>
                              <div class="whitespace-pre-line">{{ e.note }}</div>
                            </div>
                          </div>
                        </div>
                      </template>
                      <div v-if="!(s.criteria || []).some((c:any)=>c.entries && c.entries.length)" class="text-sm text-muted-foreground">No approved entries.</div>
                    </div>
                  </div>
                </div>
              </div>
              <p v-else class="text-sm text-muted-foreground">There are no skills in the PDP.</p>
            </template>
            <p v-else class="text-sm text-muted-foreground">Select a PDP to generate the Annex.</p>
          </template>
        </div>
      </div>

      <!-- PDP Modal -->
      <PdpFormModal
        v-model:open="showPdpModal"
        :form="pdpForm"
        :editing-id="editingPdpId"
        @save="onPdpModalSave"
      />

      <!-- Skill Modal -->
      <SkillFormModal
        v-model:open="showSkillModal"
        :form="skillForm"
        :criteria-items="criteriaItems"
        :criteria-text-input="criteriaTextInput"
        :add-criteria-from-input="addCriteriaFromInput"
        :remove-criteria-at="removeCriteriaAt"
        :update-criteria-at="updateCriteriaAt"
        @save="onSkillModalSave"
        @update:criteria-text-input="val => (criteriaTextInput = val)"
      />

      <!-- Criterion Progress Modal -->
      <div v-if="showProgressModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-lg rounded-xl border border-border bg-background p-4 shadow-xl">
          <div class="mb-3 flex items-center justify-between">
            <h3 class="text-base font-semibold">Progress</h3>
            <button class="rounded p-1 text-muted-foreground hover:bg-muted" @click="showProgressModal=false">✕</button>
          </div>

          <div class="space-y-3">
            <div>
              <label class="mb-1 block text-xs font-medium">Criterion</label>
              <div class="rounded-md border px-3 py-2 text-sm">{{ progressState.text }}</div>
            </div>

            <div>
              <label class="mb-1 block text-xs font-medium">Progress entries</label>
              <div class="max-h-64 overflow-auto rounded-md border divide-y">
                <div v-if="progressState.loading" class="p-3 text-xs text-muted-foreground">Loading…</div>
                <template v-else>
                  <div v-if="progressState.entries.length===0" class="p-3 text-xs text-muted-foreground">No entries yet.</div>
                  <div v-for="e in progressState.entries" :key="e.id" class="p-3 text-sm">
                    <div class="mb-1 flex items-center justify-between text-[11px] text-muted-foreground">
                      <div class="flex items-center gap-2">
                        <span>{{ e.user?.name || 'You' }} · {{ formatKyivDateTime(e.created_at) }}</span>
                        <span
                          class="inline-flex items-center rounded-md px-2 py-0.5 text-[10px]"
                          :class="e.approved ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'"
                        >{{ e.approved ? 'Approved' : 'Pending' }}</span>
                      </div>
                      <div class="flex items-center gap-1">
                        <button v-if="!e.approved && selectedPdpIsCurator" class="rounded border px-2 py-0.5 text-[10px] hover:bg-muted" @click="approveProgressEntry(e.id)">Approve</button>
                        <button v-if="selectedPdpIsOwner" class="rounded border px-2 py-0.5 text-[10px] text-destructive hover:bg-destructive hover:text-destructive-foreground" @click="deleteProgressEntry(e.id)">Delete</button>
                      </div>
                    </div>
                    <div class="whitespace-pre-line">{{ e.note }}</div>
                  </div>
                </template>
              </div>
            </div>

            <div v-if="selectedPdpIsOwner">
              <label class="mb-1 block text-xs font-medium">New entry</label>
              <textarea v-model="progressState.newNote" rows="3" class="w-full rounded-md border bg-transparent px-3 py-2 text-sm" placeholder="Describe what was done / intermediate result"></textarea>
              <div class="mt-2 flex justify-end">
                <button class="rounded bg-primary px-3 py-2 text-sm text-primary-foreground hover:opacity-90" @click="addProgressNote">Add entry</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
