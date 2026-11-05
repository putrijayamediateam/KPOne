<script setup lang="ts">
import AppShell from '@/components/AppShell.vue';
import { Toaster } from '@/components/ui/sonner';
import WorkspaceHeader from '@/components/workspace/WorkspaceHeader.vue';
import { STAFF_TOAST_PLACEMENT } from '@/lib/toastPlacement';

withDefaults(defineProps<{ focusMode?: boolean }>(), { focusMode: false });
</script>

<template>
    <AppShell variant="header">
        <WorkspaceHeader v-if="!focusMode" context="clinic" />
        <div class="flex min-h-0 w-full flex-1 flex-col">
            <slot />
        </div>
        <Toaster v-bind="STAFF_TOAST_PLACEMENT" />
    </AppShell>
</template>
