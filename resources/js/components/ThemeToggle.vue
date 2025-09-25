<script setup lang="ts">
import { computed } from 'vue';
import { useAppearance } from '@/composables/useAppearance';
import { Moon, Sun } from 'lucide-vue-next';

const { appearance, updateAppearance } = useAppearance();

// Determine the effective theme (handles 'system' as well)
const isDark = computed(() => {
  if (appearance.value === 'dark') return true;
  if (appearance.value === 'light') return false;
  // appearance === 'system' → check current applied class (set by initializeTheme/updateTheme)
  if (typeof document !== 'undefined') {
    return document.documentElement.classList.contains('dark');
  }
  return false;
});

function toggle() {
  updateAppearance(isDark.value ? 'light' : 'dark');
}
</script>

<template>
  <button
    type="button"
    :aria-pressed="isDark"
    @click="toggle"
    :title="isDark ? 'Switch to light theme' : 'Switch to dark theme'"
    class="inline-flex items-center gap-2 rounded-xl border border-black/5 bg-white px-3 py-1.5 text-sm font-normal text-[#1b1b18] shadow-[0_1px_0_rgba(0,0,0,0.04),0_1px_2px_rgba(0,0,0,0.05)] transition-colors hover:bg-[#f7f7f5] dark:border-white/10 dark:bg-[#161615] dark:text-[#EDEDEC] dark:hover:bg-[#1b1b1a]"
  >
    <Moon v-if="isDark" class="h-4 w-4 text-[#6b7280] dark:text-[#A1A09A]" />
    <Sun v-else class="h-4 w-4 text-[#6b7280] dark:text-[#A1A09A]" />
    <span>{{ isDark ? 'Dark' : 'Light' }}</span>
  </button>
</template>
