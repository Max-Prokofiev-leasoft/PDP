<script setup lang="ts">
import { formatKyivDateTime } from '@/utils/date'

type Pdp = {
  id: number
  title: string
}

const props = defineProps<{
  selectedPdp: Pdp | null
  annex: any | null
}>()
</script>

<template>
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
