<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue'
import type { NotifyPayload } from '@/composables/useNotify'
import { onNotify } from '@/composables/useNotify'

interface Toast extends Required<NotifyPayload> {}

const toasts = ref<Toast[]>([])

function addToast(p: Toast) {
  toasts.value.push(p)
  if (p.durationMs > 0) {
    const id = p.id
    setTimeout(() => removeToast(id), p.durationMs)
  }
}

function removeToast(id: number) {
  toasts.value = toasts.value.filter(t => t.id !== id)
}

let off: null | (() => void) = null
onMounted(() => {
  off = onNotify((p) => addToast(p as Toast))
})
onBeforeUnmount(() => { if (off) off() })

function typeClass(type: Toast['type']) {
  switch (type) {
    case 'success': return 'border-green-200 bg-green-50 text-green-900 dark:border-green-900/40 dark:bg-green-900/20 dark:text-green-200'
    case 'error': return 'border-red-200 bg-red-50 text-red-900 dark:border-red-900/40 dark:bg-red-900/20 dark:text-red-200'
    case 'warning': return 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-200'
    default: return 'border-muted bg-background text-foreground'
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="pointer-events-none fixed inset-0 z-[60] flex flex-col items-end gap-2 p-4">
      <transition-group name="list" tag="div" class="ml-auto flex w-full max-w-sm flex-col gap-2">
        <div v-for="t in toasts" :key="t.id" class="pointer-events-auto overflow-hidden rounded-lg border shadow-lg" :class="typeClass(t.type)">
          <div class="flex items-start gap-3 p-3">
            <div class="mt-0.5">
              <!-- Minimal outline icons using currentColor -->
              <svg v-if="t.type==='success'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path d="M9 12l2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="12" cy="12" r="9"/>
              </svg>
              <svg v-else-if="t.type==='error'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path d="M12 9v4m0 4h.01" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M12 3a9 9 0 100 18 9 9 0 000-18z"/>
              </svg>
              <svg v-else-if="t.type==='warning'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path d="M12 9v4m0 4h.01" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
              </svg>
              <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path d="M12 8h.01M11 12h1v4h1" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="12" cy="12" r="9"/>
              </svg>
            </div>
            <div class="min-w-0 flex-1">
              <div v-if="t.title" class="text-sm font-medium">{{ t.title }}</div>
              <div class="text-sm opacity-90 whitespace-pre-line">{{ t.message }}</div>
            </div>
            <button class="rounded p-1 text-sm opacity-60 hover:opacity-100" @click="removeToast(t.id)">✕</button>
          </div>
        </div>
      </transition-group>
    </div>
  </Teleport>
</template>

<style scoped>
.list-enter-active, .list-leave-active { transition: all .2s ease; }
.list-enter-from, .list-leave-to { opacity: 0; transform: translateY(8px); }
</style>
