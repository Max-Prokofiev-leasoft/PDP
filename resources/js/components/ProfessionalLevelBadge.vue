<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Award } from 'lucide-vue-next'

interface ProLevel {
  key: string
  title: string
  index: number
  closed_skills: number
  current_threshold: number
  next_threshold: number | null
  percent: number
  remaining_to_next: number | null
  at_max: boolean
}

const level = ref<ProLevel | null>(null)
const loading = ref(false)
const error = ref('')

function xsrf(): string {
  try {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
    return m ? decodeURIComponent(m[1]) : ''
  } catch { return '' }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await fetch('/profile/pro-level.json', {
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': xsrf(),
      },
    })
    if (!res.ok) throw new Error(await res.text())
    level.value = await res.json()
  } catch (e: any) {
    error.value = e?.message || 'Failed to load'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="inline-flex items-center gap-2 rounded-full border border-sidebar-border/70 dark:border-sidebar-border bg-background px-3 py-1 text-xs">
    <Award class="h-4 w-4 text-amber-500" />
    <span v-if="loading" class="text-muted-foreground">Loading…</span>
    <span v-else-if="error" class="text-destructive">—</span>
    <span v-else-if="level" class="font-medium">{{ level.title }}</span>
    <span v-else class="text-muted-foreground">No data</span>
  </div>
</template>
