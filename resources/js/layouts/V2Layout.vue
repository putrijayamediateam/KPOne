<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';
import AppShell from '@/components/AppShell.vue';
import ClockPhotoDialog from '@/components/hr/attendance/ClockPhotoDialog.vue';
import { Toaster } from '@/components/ui/sonner';
import V2AppShell from '@/components/workspace/V2AppShell.vue';
import { useMainLandmark } from '@/composables/useMainLandmark';
import {
    FOCUS_DESKTOP_TOAST_MEDIA,
    FOCUS_DESKTOP_TOAST_PLACEMENT,
} from '@/lib/focusToastPlacement';
import { STAFF_TOAST_PLACEMENT } from '@/lib/toastPlacement';

/**
 * UX-17: the layout for Version 2 pages (`Dir/V2/Name`, chosen on the server for a person in Version 2). It draws the
 * V2 sidebar shell around the page; the page sets its own width with PageFrame. If the shell is missing (the switch
 * was turned off in another tab while the page was open), the page still renders without the sidebar.
 */
// A page that asks for focus mode (the consultation page) hides the V2 top bar and keeps the Classic toast placement
// for it, exactly as `ClinicLayout` does. Every other V2 page leaves it off.
const props = withDefaults(defineProps<{ focusMode?: boolean }>(), {
    focusMode: false,
});
const desktop = useMediaQuery(FOCUS_DESKTOP_TOAST_MEDIA);
const toastPlacement = computed(() =>
    props.focusMode && desktop.value
        ? FOCUS_DESKTOP_TOAST_PLACEMENT
        : STAFF_TOAST_PLACEMENT,
);
const { content, role } = useMainLandmark();
const page = usePage();
const shell = computed(() => page.props.workspace?.shell ?? null);
</script>

<template>
    <AppShell variant="header">
        <V2AppShell v-if="shell" :shell="shell" :focus-mode="focusMode">
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
        <Toaster v-bind="toastPlacement" />
        <!-- HR-PHOTO: the camera flow is mounted once per layout; V2 pages need it as much as Classic ones. -->
        <ClockPhotoDialog />
    </AppShell>
</template>
