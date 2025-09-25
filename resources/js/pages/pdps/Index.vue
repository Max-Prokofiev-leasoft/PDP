<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head } from '@inertiajs/vue3'
import Heading from '@/components/Heading.vue'
// jsPDF will be loaded on demand from CDN to avoid bundler resolution issues
// eslint-disable-next-line @typescript-eslint/ban-ts-comment
// @ts-ignore - dynamic global import
let _jsPdfCtor: any = null;
async function getJsPdfCtor(): Promise<any> {
  if (typeof window !== 'undefined' && (window as any).jspdf?.jsPDF) {
    return (window as any).jspdf.jsPDF
  }
  if (_jsPdfCtor) return _jsPdfCtor
  await new Promise<void>((resolve, reject) => {
    const id = 'jspdf-cdn-script'
    if (document.getElementById(id)) {
      ;(document.getElementById(id) as HTMLScriptElement).addEventListener('load', () => resolve())
      return
    }
    const s = document.createElement('script')
    s.id = id
    s.src = 'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js'
    s.async = true
    s.onload = () => resolve()
    s.onerror = () => reject(new Error('Failed to load jsPDF'))
    document.head.appendChild(s)
  })
  _jsPdfCtor = (window as any).jspdf?.jsPDF
  if (!_jsPdfCtor) throw new Error('jsPDF not available after loading')
  return _jsPdfCtor
}

// Load and register a Unicode font (with Cyrillic support) for jsPDF on-demand
// Use static TTFs (Regular + Bold). Prefer DejaVu Sans (broad Unicode support) to avoid variable-font and glyph issues.
let _pdfFontBase64Regular: string | null = null
let _pdfFontBase64Bold: string | null = null
const _pdfFontName = 'DejaVuSans'
const _pdfFontStyle = 'normal'
const _pdfFontFileRegular = 'DejaVuSans.ttf'
const _pdfFontFileBold = 'DejaVuSans-Bold.ttf'

async function ensurePdfUnicodeFont(doc: any): Promise<void> {
  // Avoid re-registering for the same document instance
  if ((doc as any).__pdpFontLoaded) return

  const fetchAsBase64 = async (url: string) => {
    const res = await fetch(url, { cache: 'force-cache' })
    if (!res.ok) throw new Error(`Failed to fetch font: ${res.status}`)
    const buf = await res.arrayBuffer()
    let binary = ''
    const bytes = new Uint8Array(buf)
    for (let i = 0; i < bytes.byteLength; i++) binary += String.fromCharCode(bytes[i])
    return btoa(binary)
  }

  try {
    if (!_pdfFontBase64Regular) {
      _pdfFontBase64Regular = await fetchAsBase64('https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/fonts/DejaVuSans.ttf')
    }
    if (!_pdfFontBase64Bold) {
      _pdfFontBase64Bold = await fetchAsBase64('https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/fonts/DejaVuSans-Bold.ttf')
    }

    doc.addFileToVFS(_pdfFontFileRegular, _pdfFontBase64Regular)
    doc.addFileToVFS(_pdfFontFileBold, _pdfFontBase64Bold)
    doc.addFont(_pdfFontFileRegular, _pdfFontName, 'normal')
    doc.addFont(_pdfFontFileBold, _pdfFontName, 'bold')
    doc.setFont(_pdfFontName, _pdfFontStyle)
    ;(doc as any).__pdpFontLoaded = true
  } catch {
    // Fallback silently to default font if loading fails
    // But still try to set Helvetica to keep consistent sizing
    try { doc.setFont('helvetica', 'normal') } catch {}
  }
}

// Date-time formatting helper (Kyiv timezone)
function formatKyivDateTime(input?: string | number | Date): string {
  if (!input) return ''
  const d = new Date(input)
  if (isNaN(d.getTime())) return ''
  // Build parts to ensure YYYY-MM-DD HH:mm
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Europe/Kyiv',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(d)
  const map: Record<string, string> = {}
  for (const p of parts) map[p.type] = p.value
  return `${map.year}-${map.month}-${map.day} ${map.hour}:${map.minute}`
}

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
const importInputRef = ref<HTMLInputElement | null>(null)
function triggerImport() { importInputRef.value?.click() }
async function onImportFileChange(ev: Event) {
  const input = ev.target as HTMLInputElement
  const file = input?.files && input.files[0]
  if (!file) return
  try {
    const text = await file.text()
    const payload = JSON.parse(text)
    const created = await http('/pdps/import.json', { method: 'POST', body: JSON.stringify(payload) })
    await loadPdps()
    if (created?.id) {
      selectedPdpId.value = created.id
      await loadSkills(created.id)
    }
    alert('PDP imported successfully')
  } catch (e: any) {
    alert('Import failed: ' + (e?.message || 'Error'))
  } finally {
    if (input) input.value = ''
  }
}
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

function parseCriteriaItems(text?: string): CriteriaItem[] {
  if (!text) return []
  // Try JSON first (new format)
  try {
    const parsed = JSON.parse(text)
    if (Array.isArray(parsed)) {
      return parsed
        .map((x: any) =>
          typeof x === 'string'
            ? { text: x, done: false }
            : { text: String(x?.text ?? '').trim(), comment: x?.comment != null && String(x.comment).trim() !== '' ? String(x.comment) : undefined, done: Boolean(x?.done) }
        )
        .filter((i: CriteriaItem) => i.text)
    }
  } catch {
    // ignore and try legacy
  }
  // Legacy: split by new lines or commas/semicolons -> text only
  return text
    .split(/[\n,;]+/)
    .map(s => s.trim())
    .filter(Boolean)
    .map(t => ({ text: t }))
}

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
    alert('Failed to load progress: ' + (e?.message || 'Error'))
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
    alert('Failed to add progress entry: ' + (e?.message || 'Error'))
  }
}

async function deleteProgressEntry(id: number) {
  if (!confirm('Delete this progress entry?')) return
  try {
    await http(`/pdps/${progressState.pdp_id}/skills/${progressState.skill_id}/criteria/${progressState.index}/progress/${id}.json`, {
      method: 'DELETE'
    })
    await loadProgressEntries()
  } catch (e: any) {
    alert('Failed to delete entry: ' + (e?.message || 'Error'))
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
    alert('Failed to update criterion state: ' + (e?.message || 'Error'))
  }
}

async function approveProgressEntry(id: number) {
  try {
    await http(`/pdps/${progressState.pdp_id}/skills/${progressState.skill_id}/criteria/${progressState.index}/progress/${id}/approve.json`, {
      method: 'POST'
    })
    await loadProgressEntries()
  } catch (e: any) {
    alert('Failed to approve entry: ' + (e?.message || 'Error'))
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

  // Title and meta
  addHeading(`Annex — ${pdp.title || 'PDP'}`, 1)
  const meta: string[] = []
  if (pdp.status) meta.push(`Status: ${pdp.status}`)
  if (pdp.priority) meta.push(`Priority: ${pdp.priority}`)
  if (pdp.eta) meta.push(`ETA: ${pdp.eta}`)
  if (meta.length) addText(meta.join(' · '))
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
  const doc = await buildAnnexPdf(JsPDFCtor, annex.value)
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
  if (!pdpForm.title.trim()) return alert('Please enter PDP title')
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
  if (!confirm('Delete this PDP and all its skills?')) return
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
  if (!skillForm.skill.trim()) return alert('Please fill the "Skill to achieve" field')
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
  if (!confirm('Delete this skill?')) return
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
async function assignCurator() {
  const email = curatorEmail.value.trim()
  if (!selectedPdpId.value) return
  if (!email || !email.includes('@')) return alert('Enter a valid email')
  try {
    const res = await http(`/pdps/${selectedPdpId.value}/assign-curator.json`, { method: 'POST', body: JSON.stringify({ email }) })
    if (res?.curator) {
      const exists = curators.value.some(c => c.id === res.curator.id)
      if (!exists) curators.value.push(res.curator as Curator)
    }
    alert('Curator assigned')
    curatorEmail.value = ''
  } catch (e: any) {
    alert('Failed to assign curator: ' + (e?.message || 'Error'))
  }
}

async function removeCurator(c: Curator) {
  if (!selectedPdpId.value) return
  if (!confirm(`Remove ${c.name || c.email} from curators?`)) return
  try {
    await http(`/pdps/${selectedPdpId.value}/curators/${c.id}.json`, { method: 'DELETE' })
    curators.value = curators.value.filter(x => x.id !== c.id)
  } catch (e: any) {
    alert('Failed to remove curator: ' + (e?.message || 'Error'))
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
  } else if (!selectedPdpId.value) {
    if (pdps.value.length) selectPdp(pdps.value[0].id)
    else if (sharedPdps.value.length) selectPdpFromShared(sharedPdps.value[0].id)
  }
})
function statusBadgeClass(status: string): string {
  switch (status) {
    case 'Done':
      return 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'
    case 'In Progress':
      return 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
    case 'Blocked':
      return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300'
    default: // Planned or any other
      return 'bg-muted text-muted-foreground'
  }
}

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
              <h2 class="text-base font-semibold">Your PDPs</h2>
              <button class="rounded p-1 text-muted-foreground hover:bg-muted transition" @click="collapseOwned=!collapseOwned" :title="collapseOwned ? 'Expand' : 'Collapse'" :aria-label="collapseOwned ? 'Expand' : 'Collapse'">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 transition-transform" :class="collapseOwned ? '-rotate-90' : 'rotate-0'">
                  <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                </svg>
              </button>
            </div>
            <div class="flex items-center gap-2" v-if="activeTab!=='Annex'">
              <button class="rounded-md border px-3 py-2 text-xs hover:bg-muted" @click="triggerImport">Import PDP</button>
              <input ref="importInputRef" type="file" accept="application/json" class="hidden" @change="onImportFileChange" />
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
          <p v-else class="text-xs text-muted-foreground">Collapsed</p>

          <div class="mt-6">
            <div class="mb-3 flex items-center justify-between">
              <div class="flex items-center gap-2">
                <h2 class="text-base font-semibold">Shared PDPs</h2>
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
            <p v-else class="text-xs text-muted-foreground">Collapsed</p>
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
              <p class="mb-3 text-sm text-muted-foreground">{{ selectedPdp.description }}</p>
              <p v-if="selectedPdpIsCurator && (selectedPdp as any)?.user" class="-mt-2 mb-3 text-[11px] text-muted-foreground">Owner: {{ (selectedPdp as any).user.name || (selectedPdp as any).user.email }}<span v-if="(selectedPdp as any).user.name"> ({{ (selectedPdp as any).user.email }})</span></p>

              <div v-if="activeTab==='Manage' && selectedPdpIsOwner" class="mb-4">
                <div class="flex items-center gap-2">
                  <input v-model="curatorEmail" type="email" placeholder="Enter curator email" class="w-64 rounded border px-2 py-1 text-sm" />
                  <button class="rounded-md border px-3 py-1.5 text-xs hover:bg-muted" @click="assignCurator">Assign curator</button>
                </div>
                <div v-if="curators.length" class="mt-2 flex flex-wrap gap-2">
                  <span v-for="c in curators" :key="c.id" class="inline-flex items-center gap-2 rounded-full border px-2 py-0.5 text-xs">
                    <span class="font-medium">{{ c.name || c.email }}</span>
                    <span v-if="c.name" class="text-muted-foreground">{{ c.email }}</span>
                    <button class="ml-1 inline-flex h-5 w-5 items-center justify-center rounded-full border text-[11px] hover:bg-muted" title="Remove curator" @click="removeCurator(c)">×</button>
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
                        <div v-if="parseCriteriaItems(s.criteria).length" class="flex flex-wrap gap-1.5">
                          <div v-for="(c, i) in parseCriteriaItems(s.criteria)" :key="i" class="inline-flex items-center gap-1">
                            <button type="button" class="inline-flex items-center gap-1 rounded-full border border-border bg-muted px-2 py-0.5 text-xs hover:bg-muted/70 cursor-pointer" :title="'Click to add/view progress'" @click="openProgressModal(s, i)">
                              <span>{{ c.text }}</span>
                              <span v-if="c.comment" class="text-muted-foreground">•</span>
                            </button>
                            <button v-if="selectedPdpIsEditable" type="button" class="inline-flex items-center justify-center rounded-full border px-1.5 py-0.5 text-[10px] hover:bg-muted" :title="c.done ? 'Mark as not done' : 'Mark as done'" @click.stop="toggleCriterionDone(s, i, !c.done)">
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
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] whitespace-nowrap" :class="statusBadgeClass(s.status)">
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
              <div class="rounded-md border px-2 py-2 space-y-2">
                <div v-if="criteriaItems.length" class="space-y-2">
                  <div v-for="(item, i) in criteriaItems" :key="i" class="flex gap-2 items-start">
                    <input v-model="item.text" type="text" class="flex-1 rounded-md border bg-transparent px-2 py-1 text-xs" placeholder="Criterion" />
                    <button type="button" class="rounded border px-2 py-1 text-[11px]" @click="removeCriteriaAt(i)">Remove</button>
                  </div>
                </div>
                <div class="flex gap-2 items-start">
                  <input v-model="criteriaTextInput" @keydown.enter.prevent="addCriteriaFromInput" type="text" class="flex-1 bg-transparent px-2 py-1 text-sm border rounded-md" placeholder="New criterion" />
                  <button type="button" class="rounded bg-primary px-2 py-1 text-xs text-primary-foreground hover:opacity-90" @click="addCriteriaFromInput">Add</button>
                </div>
                <p class="text-[11px] text-muted-foreground">Comments can be added while working by clicking the criterion badge.</p>
              </div>
            </div>
          </div>

          <div class="mt-4 flex justify-end gap-2">
            <button class="rounded border px-3 py-2 text-sm hover:bg-muted" @click="showSkillModal=false">Cancel</button>
            <button class="rounded bg-primary px-3 py-2 text-sm text-primary-foreground hover:opacity-90" @click="saveSkill">Save</button>
          </div>
        </div>
      </div>

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
                          class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px]"
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
