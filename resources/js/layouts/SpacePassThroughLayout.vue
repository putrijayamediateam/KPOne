<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ClockPhotoDialog from '@/components/hr/attendance/ClockPhotoDialog.vue';
import V2AppShell from '@/components/workspace/V2AppShell.vue';
import { V2_FRAMED_SPACES } from '@/lib/v2-spaces';

// UX-24 part 1: Me home and Management sit in the V2 shell; the other spaces still draw their own chrome.
const page = usePage();
const shell = computed(() => {
    const key = (page.props.space as { key?: string } | undefined)?.key;

    return key && V2_FRAMED_SPACES.includes(key)
        ? (page.props.workspace?.shell ?? null)
        : null;
});
</script>

<template>
    <V2AppShell v-if="shell" :shell="shell">
        <slot />
    </V2AppShell>
    <slot v-else />
    <!-- HR-PHOTO: the space shell draws its own chrome; the camera flow is mounted here once for it. -->
    <ClockPhotoDialog />
</template>
