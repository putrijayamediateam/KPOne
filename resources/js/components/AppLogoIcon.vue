<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 48 48"
        :class="className"
        v-bind="$attrs"
    >
        <rect x="4" y="4" width="40" height="40" rx="12" fill="currentColor" />
        <path
            d="M15 14h6v8.2L28.6 14H36l-9.1 9.6L36.5 34h-7.7L21 25.1V34h-6V14Z"
            fill="white"
        />
        <path
            d="M31 9v6M28 12h6"
            stroke="white"
            stroke-width="2.5"
            stroke-linecap="round"
        />
    </svg>
</template>
