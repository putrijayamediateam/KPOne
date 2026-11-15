<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppShell from '@/components/AppShell.vue';
import ClockPhotoDialog from '@/components/hr/attendance/ClockPhotoDialog.vue';
import { Toaster } from '@/components/ui/sonner';
import V2AppShell from '@/components/workspace/V2AppShell.vue';
import { useMainLandmark } from '@/composables/useMainLandmark';
import { STAFF_TOAST_PLACEMENT } from '@/lib/toastPlacement';

/**
 * UX-17: the layout for Version 2 pages (`Dir/V2/Name`, chosen on the server for a person in Version 2). It draws the
 * V2 sidebar shell around the page; the page sets its own width with PageFrame. If the shell is missing (the switch
 * was turned off in another tab while the page was open), the page still renders without the sidebar.
 */
const { content, role } = useMainLandmark();
const page = usePage();
const shell = computed(() => page.props.workspace?.shell ?? null);
</script>

<template>
    <AppShell variant="header">
        <V2AppShell v-if="shell" :shell="shell">
            <div
                ref="content"
                id="main-content"
                class="flex min-h-0 w-full flex-1 flex-col"
                :role="role"
            >
                <slot />
            </div>
        </V2AppShell>
        <div
            v-else
            ref="content"
            id="main-content"
            class="flex min-h-0 w-full flex-1 flex-col"
            :role="role"
        >
            <slot />
        </div>
        <Toaster v-bind="STAFF_TOAST_PLACEMENT" />
        <!-- HR-PHOTO: the camera flow is mounted once per layout; V2 pages need it as much as Classic ones. -->
        <ClockPhotoDialog />
    </AppShell>
</template>
