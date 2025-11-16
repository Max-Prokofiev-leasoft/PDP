<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { statusBadgeClass } from '@/utils/status'
import { parseCriteriaItems } from '@/utils/criteria'

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

type Curator = { id: number; name?: string; email: string }

defineProps<{
  selectedPdp: Pdp | null
  selectedPdpIsOwner: boolean
  selectedPdpIsCurator: boolean
  selectedPdpIsEditable: boolean
  skills: PdpSkill[]
  curators: Curator[]
  curatorEmail: string
  userOptions: Curator[]
  showUserDropdown: boolean
}>()

const emit = defineEmits<{
  (e: 'openEditPdp', pdp: Pdp): void
  (e: 'openCreateSkill'): void
  (e: 'openEditSkill', skill: PdpSkill): void
  (e: 'deleteSkill', id: number): void
  (e: 'openProgressModal', s: PdpSkill, index: number): void
  (e: 'toggleCriterionDone', s: PdpSkill, index: number, done: boolean): void
  (e: 'selectUserOption', u: Curator): void
  (e: 'assignCurator'): void
  (e: 'shareToUser'): void
  (e: 'removeCurator', c: Curator): void
  (e: 'update:curatorEmail', val: string): void
  (e: 'update:showUserDropdown', val: boolean): void
}>()

// Click outside to close user dropdown
const userPickerRef = ref<HTMLElement | null>(null)
function onDocClick(e: MouseEvent) {
  const el = userPickerRef.value
  if (!el) return
  const target = e.target as Node | null
  if (target && !el.contains(target)) {
    emit('update:showUserDropdown', false)
  }
}
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))

function onEmailInput(v: string) {
  emit('update:curatorEmail', v)
}
</script>

<template>
  <template v-if="selectedPdp">
    <h3 class="mb-1 text-lg font-semibold">{{ selectedPdp.title }}</h3>
    <p class="mb-3 text-sm text-muted-foreground">{{ selectedPdp.description }}</p>
    <p v-if="selectedPdpIsCurator && (selectedPdp as any)?.user" class="-mt-2 mb-3 text-[11px] text-muted-foreground">Owner: {{ (selectedPdp as any).user.name || (selectedPdp as any).user.email }}<span v-if="(selectedPdp as any).user.name"> ({{ (selectedPdp as any).user.email }})</span></p>

    <div v-if="selectedPdpIsOwner" class="mb-4" id="pdp-share">
      <div class="flex items-center gap-2">
        <div class="relative" ref="userPickerRef">
          <input :value="curatorEmail"
                 @input="onEmailInput(($event.target as HTMLInputElement).value)"
                 @focus="emit('update:showUserDropdown', userOptions.length>0)"
                 @blur="setTimeout(()=>emit('update:showUserDropdown', false),100)"
                 @keydown.enter.prevent="emit('update:showUserDropdown', false)"
                 @keydown.esc.prevent="emit('update:showUserDropdown', false)"
                 type="text" placeholder="Enter curator email or name" class="w-64 rounded border px-2 py-1 text-sm" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" name="curatorSearch" inputmode="text" />
          <ul v-if="showUserDropdown" class="absolute z-10 mt-1 max-h-56 w-[22rem] overflow-auto rounded-md border bg-background shadow">
            <li v-for="u in userOptions" :key="u.id" class="flex cursor-pointer items-center justify-between px-2 py-1 text-sm hover:bg-muted" @mousedown.prevent="emit('selectUserOption', u)">
              <span class="font-medium">{{ u.name || u.email }}</span>
              <span class="ml-2 text-xs text-muted-foreground" v-if="u.name">{{ u.email }}</span>
            </li>
            <li v-if="!userOptions.length" class="px-2 py-1 text-xs text-muted-foreground">No matches</li>
          </ul>
        </div>
        <button class="rounded-md border px-3 py-1.5 text-xs hover:bg-muted" @click="emit('assignCurator')">Assign curator</button>
        <button class="rounded-md border px-3 py-1.5 text-xs hover:bg-muted inline-flex items-center gap-1" title="Share a copy of this PDP to the selected user" @click="emit('shareToUser')">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3.5 w-3.5">
            <path d="M15 8a3 3 0 10-2.83-4H12a3 3 0 102.17 5.03l-6.1 3.05a3 3 0 100 1.84l6.1 3.05A3 3 0 1015 12a3 3 0 00-2.17.97l-6.1-3.05A3 3 0 0012 8h.17A2.99 2.99 0 0015 8z"/>
          </svg>
          <span>Share PDP</span>
        </button>
      </div>
      <div v-if="curators.length" class="mt-2 flex flex-wrap gap-2">
        <span v-for="c in curators" :key="c.id" class="inline-flex items-center gap-2 rounded-md border px-2 py-0.5 text-xs">
          <span class="font-medium">{{ c.name || c.email }}</span>
          <span v-if="c.name" class="text-muted-foreground">{{ c.email }}</span>
          <button class="ml-1 inline-flex h-5 w-5 items-center justify-center rounded-md border text-[11px] hover:bg-muted" title="Remove curator" @click="emit('removeCurator', c)">×</button>
        </span>
      </div>
    </div>

    <div v-if="skills.length">
      <!-- Desktop/tablet: table view -->
      <div class="hidden lg:block overflow-x-auto">
        <table class="min-w-full text-xs sm:text-sm">
          <thead class="sticky top-0 z-10 bg-background">
          <tr class="border-b text-left text-muted-foreground">
            <th class="px-2 py-1 sm:px-3 sm:py-2 sticky left-0 bg-background">Skill to achieve</th>
            <th class="px-2 py-1 sm:px-3 sm:py-2">Description</th>
            <th class="px-2 py-1 sm:px-3 sm:py-2">Win Criteria</th>
            <th class="px-2 py-1 sm:px-3 sm:py-2">Prio</th>
            <th class="px-2 py-1 sm:px-3 sm:py-2">ETA</th>
            <th class="px-2 py-1 sm:px-3 sm:py-2">Status</th>
            <th class="px-2 py-1 sm:px-3 sm:py-2"></th>
          </tr>
          </thead>
          <tbody>
          <tr v-for="s in skills" :key="s.id" class="border-b align-top">
            <td class="px-2 py-2 sm:px-3 sm:py-3 font-medium sticky left-0 bg-background">{{ s.skill }}</td>
            <td class="px-2 py-2 sm:px-3 sm:py-3 whitespace-pre-line break-words">{{ s.description }}</td>
            <td class="px-2 py-2 sm:px-3 sm:py-3">
              <div v-if="parseCriteriaItems(s.criteria).length" class="flex flex-col gap-1.5">
                <div v-for="(c, i) in parseCriteriaItems(s.criteria)" :key="i" class="flex items-start gap-1 w-full">
                  <button type="button" class="inline-flex flex-1 items-start justify-between rounded-md border border-border bg-muted px-2 py-1 text-xs hover:bg-muted/70 cursor-pointer text-left" :title="'Click to add/view progress'" @click="emit('openProgressModal', s, i)">
                    <span class="whitespace-normal break-words">{{ c.text }}</span>
                    <span v-if="c.comment" class="ml-2 shrink-0 text-muted-foreground">•</span>
                  </button>
                  <button v-if="selectedPdpIsEditable" type="button" class="inline-flex h-[22px] w-[22px] flex-none items-center justify-center rounded-md border text-[10px] hover:bg-muted" :title="c.done ? 'Mark as not done' : 'Mark as done'" @click.stop="emit('toggleCriterionDone', s, i, !c.done)">
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
            <td class="px-2 py-2 sm:px-3 sm:py-3">{{ s.priority }}</td>
            <td class="px-2 py-2 sm:px-3 sm:py-3">{{ s.eta }}</td>
            <td class="px-2 py-2 sm:px-3 sm:py-3">
              <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] whitespace-nowrap" :class="statusBadgeClass(s.status)">
                {{ s.status }}
              </span>
            </td>
            <td class="px-2 py-2 sm:px-3 sm:py-3 text-right">
              <div class="flex justify-end gap-2" v-if="selectedPdpIsEditable">
                <button class="rounded border px-2 py-1 text-xs hover:bg-muted" @click="emit('openEditSkill', s)">Edit</button>
                <button class="rounded border px-2 py-1 text-xs text-destructive hover:bg-destructive hover:text-destructive-foreground" @click="emit('deleteSkill', s.id)">Delete</button>
              </div>
            </td>
          </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile: cards view -->
      <div class="lg:hidden space-y-3">
        <article v-for="s in skills" :key="s.id" class="rounded-lg border bg-background p-3 shadow-sm">
          <header class="flex items-start justify-between gap-2">
            <h4 class="font-semibold text-base leading-snug">{{ s.skill }}</h4>
            <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] whitespace-nowrap" :class="statusBadgeClass(s.status)">{{ s.status }}</span>
          </header>
          <p v-if="s.description" class="mt-1 text-sm text-muted-foreground whitespace-pre-line">{{ s.description }}</p>
          <div v-if="parseCriteriaItems(s.criteria).length" class="mt-2 space-y-1.5">
            <div class="text-xs text-muted-foreground">Win criteria</div>
            <div v-for="(c, i) in parseCriteriaItems(s.criteria)" :key="i" class="flex items-start gap-1 w-full">
              <button type="button" class="inline-flex flex-1 items-start justify-between rounded-md border border-border bg-muted px-2 py-1 text-xs hover:bg-muted/70 cursor-pointer text-left" :title="'Click to add/view progress'" @click="emit('openProgressModal', s, i)">
                <span class="whitespace-normal break-words">{{ c.text }}</span>
                <span v-if="c.comment" class="ml-2 shrink-0 text-muted-foreground">•</span>
              </button>
              <button v-if="selectedPdpIsEditable" type="button" class="inline-flex h-[22px] w-[22px] flex-none items-center justify-center rounded-md border text-[10px] hover:bg-muted" :title="c.done ? 'Mark as not done' : 'Mark as done'" @click.stop="emit('toggleCriterionDone', s, i, !c.done)">
                <svg v-if="!c.done" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3">
                  <path fill-rule="evenodd" d="M3.5 10a6.5 6.5 0 1113 0 6.5 6.5 0 01-13 0zm9.204-2.79a1 1 0 10-1.414-1.414L8.5 8.586 7.21 7.296a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l3.494-3.5z" clip-rule="evenodd" />
                </svg>
                <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3 text-green-600">
                  <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.414l-7.5 7.5a1 1 0 01-1.414 0l-3-3a1 1 0 111.414-1.414l2.293 2.293 6.793-6.793a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
              </button>
            </div>
          </div>
          <footer class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <div class="text-xs text-muted-foreground">Prio: <span class="font-medium">{{ s.priority }}</span><span v-if="s.eta"> · ETA: {{ s.eta }}</span></div>
            <div class="flex gap-2" v-if="selectedPdpIsEditable">
              <button class="rounded border px-2 py-1 text-xs hover:bg-muted" @click="emit('openEditSkill', s)">Edit</button>
              <button class="rounded border px-2 py-1 text-xs text-destructive hover:bg-destructive hover:text-destructive-foreground" @click="emit('deleteSkill', s.id)">Delete</button>
            </div>
          </footer>
        </article>
      </div>
    </div>
    <p v-else class="text-sm text-muted-foreground">No skills in this PDP. Add the first one.</p>
  </template>
  <p v-else class="text-sm text-muted-foreground">Select a PDP to view its skills.</p>
</template>
