<script setup lang="ts">
import type { Component } from 'vue';
import { ref } from 'vue';

/** One icon button of the New look rail (V2NewLookSidebar). Named by the area, 44 px, focus ring as an offset outline. */
defineProps<{
    title: string;
    icon: Component;
    stateClass: string;
    current: boolean;
    expanded: boolean;
    tabbable: boolean;
}>();
defineEmits<{
    pick: [];
    keydown: [event: KeyboardEvent];
    focus: [event: FocusEvent];
    blur: [];
    enter: [event: MouseEvent];
    leave: [];
}>();

const el = ref<HTMLButtonElement | null>(null);
defineExpose({ el });
</script>

<template>
    <button
        ref="el"
        type="button"
        class="relative flex size-11 shrink-0 items-center justify-center rounded-[14px] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
        :class="stateClass"
        :aria-label="title"
        :aria-current="current ? 'true' : undefined"
        :aria-expanded="expanded"
        :tabindex="tabbable ? 0 : -1"
        data-test="v2-newlook-area"
        @click="$emit('pick')"
        @keydown="$emit('keydown', $event)"
        @focus="$emit('focus', $event)"
        @blur="$emit('blur')"
        @mouseenter="$emit('enter', $event)"
        @mouseleave="$emit('leave')"
    >
        <component :is="icon" class="size-5" aria-hidden="true" />
    </button>
</template>
