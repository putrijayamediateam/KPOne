<script setup lang="ts">
import { computed } from 'vue';
import type { CallNextBlock } from '@/types';

const props = defineProps<{
    block?: CallNextBlock | null;
    roomChoice?: {
        rooms: Array<{ id: number; name: string }>;
        currentRoomId: number | null;
        currentRoomSource: 'chosen' | 'roster' | null;
    } | null;
}>();
const selectedRoom = computed(() =>
    props.roomChoice?.rooms.find(
        (room) => room.id === props.roomChoice?.currentRoomId,
    ),
);
const candidate = computed(() =>
    props.block?.state === 'ready' ? props.block.candidate : null,
);
</script>

<template>
    <section
        v-if="roomChoice || block"
        aria-label="Room and next patient context"
        class="flex flex-wrap items-start justify-between gap-3 rounded-xl border bg-card px-4 py-3 text-sm"
    >
        <div v-if="roomChoice" class="min-w-0 space-y-1 break-words">
            <p>
                <span class="text-muted-foreground">Room selection: </span>
                {{
                    selectedRoom?.name ??
                    (roomChoice.currentRoomId === null
                        ? 'No room selected'
                        : 'Room selection unavailable')
                }}
            </p>
            <p
                v-if="selectedRoom && roomChoice.currentRoomSource"
                class="text-xs text-muted-foreground"
            >
                {{
                    roomChoice.currentRoomSource === 'roster'
                        ? 'From the approved roster'
                        : 'Selected manually'
                }}
            </p>
        </div>
        <div v-if="candidate" class="min-w-0 space-y-1 break-words">
            <p>
                <span class="text-muted-foreground">{{
                    block?.mode === 'branch' && block.doctorName
                        ? `Next for ${block.doctorName}: `
                        : 'Next patient: '
                }}</span>
                {{ candidate.queueNumber }} · {{ candidate.patientName }}
            </p>
            <p class="text-xs text-muted-foreground">
                Call destination: {{ candidate.roomName ?? 'Counter' }}
            </p>
        </div>
        <p v-else-if="block?.reason" class="text-muted-foreground">
            {{ block.reason }}
        </p>
    </section>
</template>
