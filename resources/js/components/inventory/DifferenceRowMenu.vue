<script setup lang="ts">
/**
 * STK-45: the row menu for one balance. With a live count open the item reads "Add to the open count", so a chip and a
 * menu item never seem to do the same thing (Daniel). The trigger is 44 px below 1024 px.
 */
import { MoreHorizontal } from '@lucide/vue';
import { ref } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { DifferenceState } from './ReportDifferenceSheet.vue';

defineProps<{ difference: DifferenceState; label: string }>();
const emit = defineEmits<{ report: [trigger: HTMLElement | null] }>();
const trigger = ref<{ $el?: HTMLElement } | HTMLElement | null>(null);

function element(): HTMLElement | null {
    const value = trigger.value;

    return value instanceof HTMLElement ? value : (value?.$el ?? null);
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                ref="trigger"
                type="button"
                class="inline-flex size-11 items-center justify-center rounded-[10px] hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:size-9"
                :aria-label="`More actions for ${label}`"
                data-testid="difference-row-menu"
            >
                <MoreHorizontal class="size-4" aria-hidden="true" />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <DropdownMenuItem
                class="max-lg:min-h-11"
                @select="emit('report', element())"
                >{{
                    difference.openCount
                        ? 'Add to the open count'
                        : 'Report a difference'
                }}</DropdownMenuItem
            >
        </DropdownMenuContent>
    </DropdownMenu>
</template>
