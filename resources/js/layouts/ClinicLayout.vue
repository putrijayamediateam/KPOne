<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';
import AppShell from '@/components/AppShell.vue';
import { Toaster } from '@/components/ui/sonner';
import WorkspaceHeader from '@/components/workspace/WorkspaceHeader.vue';
import { useMainLandmark } from '@/composables/useMainLandmark';
import {
    FOCUS_DESKTOP_TOAST_MEDIA,
    FOCUS_DESKTOP_TOAST_PLACEMENT,
} from '@/lib/focusToastPlacement';
import { STAFF_TOAST_PLACEMENT } from '@/lib/toastPlacement';

const props = withDefaults(defineProps<{ focusMode?: boolean }>(), {
    focusMode: false,
});

const { content, role } = useMainLandmark();

const desktop = useMediaQuery(FOCUS_DESKTOP_TOAST_MEDIA);
const toastPlacement = computed(() =>
    props.focusMode && desktop.value
        ? FOCUS_DESKTOP_TOAST_PLACEMENT
        : STAFF_TOAST_PLACEMENT,
);
</script>

<template>
    <AppShell variant="header">
        <WorkspaceHeader v-if="!focusMode" context="clinic" />
        <div
            ref="content"
            id="main-content"
            class="flex min-h-0 w-full flex-1 flex-col"
            :role="role"
        >
            <slot />
        </div>
        <Toaster v-bind="toastPlacement" />
    </AppShell>
</template>
