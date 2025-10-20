<script setup lang="ts">
import { ref, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog'

interface Level { key: string; title: string; threshold: number }

const isOpen = defineModel<boolean>('isOpen')

const levels = ref<Level[]>([])
const selected = ref<string>('')
const loading = ref(false)
const error = ref('')

const emit = defineEmits<{ (e: 'saved', level: string): void }>()

function xsrf(): string {
  try {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)
    return m ? decodeURIComponent(m[1]) : ''
  } catch { return '' }
}

async function fetchLevels() {
  error.value = ''
  try {
    const res = await fetch('/profile/pro-level.json', { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
    if (!res.ok) throw new Error(await res.text())
    const data = await res.json()
    levels.value = (data?.levels || []) as Level[]
    if (!selected.value && levels.value.length) selected.value = levels.value[0].key
  } catch (e: any) {
    error.value = e?.message || 'Failed to load levels'
  }
}

async function save() {
  if (!selected.value) return
  loading.value = true
  error.value = ''
  try {
    const res = await fetch('/profile/pro-level/start.json', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': xsrf(),
      },
      body: JSON.stringify({ level: selected.value }),
    })
    if (!res.ok) throw new Error(await res.text())
    emit('saved', selected.value)
    isOpen.value = false
  } catch (e: any) {
    error.value = e?.message || 'Failed to save level'
  } finally {
    loading.value = false
  }
}

watch(() => isOpen.value, (open) => { if (open) fetchLevels() })
</script>

<template>
  <Dialog :open="isOpen" @update:open="isOpen = $event">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>Select your professional level</DialogTitle>
        <DialogDescription>
          Choose the level that best matches your current experience. This will set your starting point in the skill ladder.
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-3">
        <div v-if="error" class="text-sm text-destructive">{{ error }}</div>
        <div v-else>
          <div class="grid gap-2">
            <label v-for="lvl in levels" :key="lvl.key" class="flex cursor-pointer items-center gap-3 rounded border p-2 hover:bg-muted">
              <input type="radio" :value="lvl.key" v-model="selected" name="level" />
              <div class="flex flex-col">
                <div class="text-sm font-medium">{{ lvl.title }}</div>
                <div class="text-[11px] text-muted-foreground">Starts at {{ lvl.threshold }} closed skills</div>
              </div>
            </label>
          </div>
        </div>

        <div class="flex items-center gap-2 pt-2">
          <Button type="button" variant="outline" class="flex-1" @click="isOpen = false" :disabled="loading">Cancel</Button>
          <Button type="button" class="flex-1" @click="save" :disabled="loading || !selected">Confirm</Button>
        </div>
      </div>
    </DialogContent>
  </Dialog>
</template>
