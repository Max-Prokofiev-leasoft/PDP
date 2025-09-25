<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <!-- Replace default Laravel-like SVG with LeaSoft logo image -->
    <img
        src="/images/lea-soft.png"
        alt="LeaSoft logo"
        :class="className"
        v-bind="$attrs"
    />
</template>
