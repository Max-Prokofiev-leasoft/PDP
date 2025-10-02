<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue'

const open = ref(false)
const message = ref('')
let resolver: null | ((v: boolean) => void) = null

function onOpen(e: CustomEvent) {
  const d = e.detail as { message: string; resolve: (v: boolean) => void }
  message.value = d.message || ''
  resolver = d.resolve
  open.value = true
}

function close(v: boolean) {
  open.value = false
  if (resolver) resolver(v)
  resolver = null
}

onMounted(() => {
  window.addEventListener('app:confirm:open', onOpen as EventListener)
})

onBeforeUnmount(() => {
  window.removeEventListener('app:confirm:open', onOpen as EventListener)
})
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-[70] flex items-center justify-center bg-black/50 p-4">
      <div class="w-full max-w-sm rounded-xl border border-border bg-background p-4 shadow-xl">
        <div class="mb-2 text-sm">{{ message }}</div>
        <div class="mt-4 flex justify-end gap-2">
          <button class="rounded border px-3 py-2 text-xs hover:bg-muted" @click="close(false)">Cancel</button>
          <button class="rounded bg-primary px-3 py-2 text-xs font-medium text-primary-foreground hover:opacity-90" @click="close(true)">OK</button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
