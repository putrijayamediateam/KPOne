<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';
import AppShell from '@/components/AppShell.vue';
import { Toaster } from '@/components/ui/sonner';
import V2AppShell from '@/components/workspace/V2AppShell.vue';
import WorkspaceHeader from '@/components/workspace/WorkspaceHeader.vue';
import { useMainLandmark } from '@/composables/useMainLandmark';
import { useUiVersion } from '@/composables/useUiVersion';
import {
    FOCUS_DESKTOP_TOAST_MEDIA,
    FOCUS_DESKTOP_TOAST_PLACEMENT,
} from '@/lib/focusToastPlacement';
import { STAFF_TOAST_PLACEMENT } from '@/lib/toastPlacement';

const props = withDefaults(defineProps<{ focusMode?: boolean }>(), {
    focusMode: false,
});

const { content, role } = useMainLandmark();

// UX-17: in Version 2 the page sits inside the spaces' sidebar shell. Classic renders the markup below unchanged.
const { isV2 } = useUiVersion();
const page = usePage();
const v2Shell = computed(() =>
    isV2.value ? (page.props.workspace?.shell ?? null) : null,
);

const desktop = useMediaQuery(FOCUS_DESKTOP_TOAST_MEDIA);
const toastPlacement = computed(() =>
    props.focusMode && desktop.value
        ? FOCUS_DESKTOP_TOAST_PLACEMENT
        : STAFF_TOAST_PLACEMENT,
);
</script>

<template>
    <AppShell variant="header">
        <V2AppShell v-if="v2Shell" :shell="v2Shell" :focus-mode="focusMode">
            <div
                ref="content"
                id="main-content"
                class="flex min-h-0 w-full flex-1 flex-col"
                :role="role"
            >
                <slot />
            </div>
        </V2AppShell>
        <template v-else>
            <WorkspaceHeader v-if="!focusMode" context="clinic" />
            <div
                ref="content"
                id="main-content"
                class="flex min-h-0 w-full flex-1 flex-col"
                :role="role"
            >
                <slot />
            </div>
        </template>
        <Toaster v-bind="toastPlacement" />
    </AppShell>
</template>
