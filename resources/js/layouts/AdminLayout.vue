<script setup lang="ts">
import AppShell from '@/components/AppShell.vue';
import ClockPhotoDialog from '@/components/hr/attendance/ClockPhotoDialog.vue';
import WorkspaceRail from '@/components/shell/WorkspaceRail.vue';
import { Toaster } from '@/components/ui/sonner';
import { useMainLandmark } from '@/composables/useMainLandmark';
import { STAFF_TOAST_PLACEMENT } from '@/lib/toastPlacement';

const { content, role } = useMainLandmark();
</script>

<template>
    <AppShell variant="header">
        <WorkspaceRail context="admin" />
        <div
            ref="content"
            id="main-content"
            class="mx-auto flex min-h-0 w-full max-w-7xl flex-1 flex-col lg:pl-[var(--shell-offset,72px)]"
            :role="role"
        >
            <slot />
        </div>
        <Toaster v-bind="STAFF_TOAST_PLACEMENT" />
        <!-- HR-PHOTO: one camera flow for every Clock in and Clock out button on these pages. -->
        <ClockPhotoDialog />
    </AppShell>
</template>
