<script setup lang="ts">
import { Clock } from '@lucide/vue';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { useClockShortcut } from '@/composables/useClockShortcut';

// One Clock out confirm for the top-bar button and the phone menu row; mounted once so it outlives the menu.
const { clock, confirmOut } = useClockShortcut();
</script>

<template>
    <OperationalConfirmDialog
        v-model:open="clock.confirmOut"
        title="Clock out now?"
        description="Your clock-out time is the server time when you confirm."
        confirm-label="Clock out"
        processing-label="Clocking out…"
        :processing="clock.processing"
        @confirm="confirmOut()"
    >
        <template #icon>
            <Clock class="size-5" />
        </template>
    </OperationalConfirmDialog>
</template>
