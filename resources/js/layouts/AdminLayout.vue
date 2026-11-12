<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppShell from '@/components/AppShell.vue';
import { Toaster } from '@/components/ui/sonner';
import V2AppShell from '@/components/workspace/V2AppShell.vue';
import WorkspaceHeader from '@/components/workspace/WorkspaceHeader.vue';
import { useMainLandmark } from '@/composables/useMainLandmark';
import { useUiVersion } from '@/composables/useUiVersion';
import { STAFF_TOAST_PLACEMENT } from '@/lib/toastPlacement';

const { content, role } = useMainLandmark();

// UX-17: in Version 2 the page sits inside the spaces' sidebar shell. Classic renders the markup below unchanged.
const { isV2 } = useUiVersion();
const page = usePage();
const v2Shell = computed(() =>
    isV2.value ? (page.props.workspace?.shell ?? null) : null,
);
</script>

<template>
    <AppShell variant="header">
        <V2AppShell v-if="v2Shell" :shell="v2Shell">
            <div
                ref="content"
                id="main-content"
                class="mx-auto flex min-h-0 w-full max-w-7xl flex-1 flex-col"
                :role="role"
            >
                <slot />
            </div>
        </V2AppShell>
        <template v-else>
            <WorkspaceHeader context="admin" />
            <div
                ref="content"
                id="main-content"
                class="mx-auto flex min-h-0 w-full max-w-7xl flex-1 flex-col"
                :role="role"
            >
                <slot />
            </div>
        </template>
        <Toaster v-bind="STAFF_TOAST_PLACEMENT" />
    </AppShell>
</template>
