<script setup lang="ts">
import { computed } from 'vue'

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

const props = defineProps<{
  pdps: Pdp[]
  sharedPdps: Pdp[]
  selectedPdpId: number | null
  collapseOwned: boolean
  collapseShared: boolean
  activeTab: 'Manage' | 'Annex'
}>()

const emit = defineEmits<{
  (e: 'update:collapseOwned', val: boolean): void
  (e: 'update:collapseShared', val: boolean): void
  (e: 'selectPdp', id: number): void
  (e: 'selectPdpFromShared', id: number): void
  (e: 'openCreatePdp'): void
  (e: 'openEditPdp', pdp: Pdp): void
  (e: 'deletePdp', id: number): void
}>()

const hasPdps = computed(() => props.pdps.length > 0)
const hasSharedPdps = computed(() => props.sharedPdps.length > 0)
</script>

<template>
  <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
    <div class="mb-3 flex items-center justify-between">
      <div class="flex items-center gap-2">
        <h2 class="text-base font-semibold flex items-center gap-2">
          Your PDPs
          <span class="inline-flex items-center justify-center rounded-md border px-1.5 py-0.5 text-[10px] leading-none min-w-[18px] text-muted-foreground">{{ pdps.length }}</span>
        </h2>
        <button class="rounded p-1 text-muted-foreground hover:bg-muted transition"
                @click="emit('update:collapseOwned', !props.collapseOwned)"
                :title="collapseOwned ? 'Expand' : 'Collapse'"
                :aria-label="collapseOwned ? 'Expand' : 'Collapse'">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 transition-transform" :class="collapseOwned ? '-rotate-90' : 'rotate-0'">
            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
          </svg>
        </button>
      </div>
      <div class="flex items-center gap-2" v-if="activeTab!=='Annex'">
        <button class="rounded-md bg-primary px-3 py-2 text-xs font-medium text-primary-foreground hover:opacity-90" @click="emit('openCreatePdp')">+ Add PDP</button>
      </div>
    </div>

    <div v-if="!collapseOwned">
      <div v-if="hasPdps" class="space-y-1">
        <button v-for="p in pdps" :key="p.id"
                class="w-full rounded-md border px-3 py-2 text-left text-sm hover:bg-muted"
                :class="selectedPdpId===p.id ? 'border-primary' : 'border-border'"
                @click="emit('selectPdp', p.id)">
          <div class="flex items-center justify-between">
            <span class="font-medium">{{ p.title }}</span>
            <span class="text-xs text-muted-foreground">{{ p.skills_count ?? 0 }} skills</span>
          </div>
          <div class="text-xs text-muted-foreground">{{ p.status }} · {{ p.priority }}<span v-if="p.eta"> · ETA: {{ p.eta }}</span></div>
          <div class="mt-2 flex gap-2">
            <button class="rounded border px-2 py-1 text-[11px] hover:bg-muted" @click.stop="emit('openEditPdp', p)">Edit</button>
            <button class="rounded border px-2 py-1 text-[11px] text-destructive hover:bg-destructive hover:text-destructive-foreground" @click.stop="emit('deletePdp', p.id)">Delete</button>
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
          <button class="rounded p-1 text-muted-foreground hover:bg-muted transition"
                  @click="emit('update:collapseShared', !props.collapseShared)"
                  :title="collapseShared ? 'Expand' : 'Collapse'"
                  :aria-label="collapseShared ? 'Expand' : 'Collapse'">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 transition-transform" :class="collapseShared ? '-rotate-90' : 'rotate-0'">
              <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.24 4.5a.75.75 0 01-1.08 0l-4.24-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
            </svg>
          </button>
        </div>
      </div>
      <div v-if="!collapseShared">
        <div v-if="hasSharedPdps" class="space-y-1">
          <button v-for="p in sharedPdps" :key="'s-'+p.id" class="w-full rounded-md border px-3 py-2 text-left text-sm hover:bg-muted" :class="selectedPdpId===p.id ? 'border-primary' : 'border-border'" @click="emit('selectPdpFromShared', p.id)">
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
</template>
