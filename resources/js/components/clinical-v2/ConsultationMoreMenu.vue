<script setup lang="ts">
import { ExternalLink } from '@lucide/vue';
import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuPortal,
    DropdownMenuRoot,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from 'reka-ui';
import { ref } from 'vue';

/**
 * The phone "More" menu of the Version 2 consultation toolbar. Below 640 px the document links (each opens in a new
 * tab, so the unsaved form stays here) and the treatment-room calls go behind this one menu, with 44 px items; the
 * page keeps Hold, Resume, Call again, the status and the waiting-patients list in the toolbar. The menu holds no
 * permission logic: the page passes only what the doctor may use. Choosing a room emits `room`; the page makes the
 * same call as the inline "Call to treatment room" button.
 */
defineProps<{
    documents: Array<{ id: string; label: string; href: string }>;
    rooms: Array<{ id: number; name: string }>;
    roomsDisabled?: boolean;
}>();
defineEmits<{ room: [roomId: number] }>();

const open = ref(false);
</script>

<template>
    <DropdownMenuRoot v-model:open="open">
        <DropdownMenuTrigger
            data-testid="consultation-more"
            aria-label="More actions"
            class="inline-flex h-11 items-center gap-2 rounded-[10px] border border-border bg-card px-3 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background data-[state=open]:bg-muted"
        >
            <svg
                class="size-4"
                viewBox="0 0 24 24"
                fill="currentColor"
                aria-hidden="true"
            >
                <circle cx="5" cy="12" r="1.75" />
                <circle cx="12" cy="12" r="1.75" />
                <circle cx="19" cy="12" r="1.75" />
            </svg>
            More
        </DropdownMenuTrigger>
        <DropdownMenuPortal>
            <DropdownMenuContent
                align="start"
                :side-offset="6"
                class="v2-menu z-50 max-w-[min(20rem,calc(100vw-2rem))] min-w-60 rounded-[14px] border border-border bg-popover p-1.5 text-popover-foreground dark:border-foreground/20"
            >
                <DropdownMenuLabel
                    v-if="documents.length"
                    class="px-2.5 pt-1.5 pb-1 text-xs font-medium text-muted-foreground"
                    >Documents</DropdownMenuLabel
                >
                <DropdownMenuItem
                    v-for="doc in documents"
                    :key="doc.id"
                    as-child
                    class="flex min-h-11 cursor-default items-center rounded-[8px] px-2.5 text-sm outline-none select-none data-[highlighted]:bg-muted"
                >
                    <a
                        :href="doc.href"
                        target="_blank"
                        rel="noopener"
                        class="flex min-h-11 items-center justify-between gap-2 px-2.5 text-sm font-medium"
                        >{{ doc.label
                        }}<ExternalLink
                            class="size-3.5"
                            aria-hidden="true"
                        /><span class="sr-only">(opens in a new tab)</span></a
                    >
                </DropdownMenuItem>
                <DropdownMenuSeparator
                    v-if="documents.length && rooms.length"
                    class="mx-1 my-1.5 h-px bg-border"
                />
                <DropdownMenuLabel
                    v-if="rooms.length"
                    class="px-2.5 pt-1.5 pb-1 text-xs font-medium text-muted-foreground"
                    >Call to treatment room</DropdownMenuLabel
                >
                <DropdownMenuItem
                    v-for="room in rooms"
                    :key="room.id"
                    :disabled="roomsDisabled"
                    class="flex min-h-11 cursor-default items-center rounded-[8px] px-2.5 text-sm font-medium outline-none select-none data-[disabled]:opacity-50 data-[highlighted]:bg-muted"
                    @select="$emit('room', room.id)"
                    >{{ room.name }}</DropdownMenuItem
                >
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
