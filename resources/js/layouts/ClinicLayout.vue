<script setup lang="ts">
import AppShell from '@/components/AppShell.vue';
import { Toaster } from '@/components/ui/sonner';
import WorkspaceHeader from '@/components/workspace/WorkspaceHeader.vue';
</script>

<template>
    <AppShell variant="header">
        <WorkspaceHeader context="clinic" />
        <div class="flex min-h-0 w-full flex-1 flex-col">
            <slot />
        </div>
        <Toaster />
    </AppShell>
</template>
