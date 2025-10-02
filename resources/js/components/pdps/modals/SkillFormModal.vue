<script setup lang="ts">
import { defineProps, defineEmits, reactive, watch } from 'vue'
import type { PdpSkill } from '@/pages/pdps/Index.vue'

interface CriteriaItem { text: string; comment?: string; done?: boolean }

const props = defineProps<{
  open: boolean
  form: PdpSkill
  criteriaItems: CriteriaItem[]
  criteriaTextInput: string
  addCriteriaFromInput: () => void
  removeCriteriaAt: (i: number) => void
  updateCriteriaAt: (i: number, text: string) => void
}>()

const emit = defineEmits<{
  (e: 'update:open', v: boolean): void
  (e: 'update:criteria-text-input', v: string): void
  (e: 'save', v: PdpSkill): void
}>()

const localForm = reactive<PdpSkill>({ id: 0, pdp_id: 0, skill: '', description: '', criteria: '', priority: 'Medium', eta: '', status: 'Planned' })

watch(() => props.open, (v) => {
  if (v) Object.assign(localForm, props.form)
})

function close() { emit('update:open', false) }
function save() { emit('save', { ...localForm }) }
</script>

<template>
  <div v-if="props.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
    <div class="w-full max-w-3xl rounded-xl border border-border bg-background p-4 shadow-xl">
      <div class="mb-3 flex items-center justify-between">
        <h3 class="text-base font-semibold">{{ localForm.id ? 'Edit Skill' : 'Add Skill' }}</h3>
        <button class="rounded p-1 text-muted-foreground hover:bg-muted" @click="close">✕</button>
      </div>

      <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
        <div class="md:col-span-1">
          <label class="mb-1 block text-xs font-medium">Skill to achieve</label>
          <input v-model="localForm.skill" type="text" class="w-full rounded-md border bg-transparent px-3 py-2 text-sm" placeholder="e.g. Intermediate level of English" />
        </div>
        <div class="md:col-span-1">
          <label class="mb-1 block text-xs font-medium">Prio</label>
          <select v-model="localForm.priority" class="w-full rounded-md border bg-transparent px-3 py-2 text-sm">
            <option>Low</option>
            <option>Medium</option>
            <option>High</option>
          </select>
        </div>

        <div class="md:col-span-1">
          <label class="mb-1 block text-xs font-medium">ETA</label>
          <input v-model="localForm.eta" type="text" class="w-full rounded-md border bg-transparent px-3 py-2 text-sm" placeholder="e.g. Q4 2025 or 31.12.2025" />
        </div>
        <div class="md:col-span-1">
          <label class="mb-1 block text-xs font-medium">Status</label>
          <select v-model="localForm.status" class="w-full rounded-md border bg-transparent px-3 py-2 text-sm">
            <option>Planned</option>
            <option>In Progress</option>
            <option>Done</option>
            <option>Blocked</option>
          </select>
        </div>

        <div class="md:col-span-1">
          <label class="mb-1 block text-xs font-medium">Description</label>
          <textarea v-model="localForm.description" rows="6" class="w-full rounded-md border bg-transparent px-3 py-2 text-sm" placeholder="Short description"></textarea>
        </div>
        <div class="md:col-span-1">
          <label class="mb-1 block text-xs font-medium">Win Criteria</label>
          <div class="rounded-md border px-2 py-2 space-y-2">
            <div v-if="props.criteriaItems.length" class="space-y-2">
              <div v-for="(item, i) in props.criteriaItems" :key="i" class="flex gap-2 items-start">
                <input :value="item.text" @input="props.updateCriteriaAt(i, ($event.target as HTMLInputElement).value)" type="text" class="flex-1 rounded-md border bg-transparent px-2 py-1 text-xs" placeholder="Criterion" />
                <button type="button" class="rounded border px-2 py-1 text-[11px]" @click="props.removeCriteriaAt(i)">Remove</button>
              </div>
            </div>
            <div class="flex gap-2 items-start">
              <input :value="props.criteriaTextInput" @input="emit('update:criteria-text-input', ($event.target as HTMLInputElement).value)" @keydown.enter.prevent="props.addCriteriaFromInput" type="text" class="flex-1 bg-transparent px-2 py-1 text-sm border rounded-md" placeholder="New criterion" />
              <button type="button" class="rounded bg-primary px-2 py-1 text-xs text-primary-foreground hover:opacity-90" @click="props.addCriteriaFromInput">Add</button>
            </div>
            <p class="text-[11px] text-muted-foreground">Comments can be added while working by clicking the criterion badge.</p>
          </div>
        </div>
      </div>

      <div class="mt-4 flex justify-end gap-2">
        <button class="rounded border px-3 py-2 text-sm hover:bg-muted" @click="close">Cancel</button>
        <button class="rounded bg-primary px-3 py-2 text-sm text-primary-foreground hover:opacity-90" @click="save">Save</button>
      </div>
    </div>
  </div>
</template>
