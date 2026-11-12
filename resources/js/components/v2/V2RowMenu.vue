<script setup lang="ts">
import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuPortal,
    DropdownMenuRoot,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from 'reka-ui';

/**
 * Version 2 row menu: the "…" popover with grouped items (Open, Move, Call, Money, Danger). It holds NO permission
 * logic: the page passes each item's `allowed` state from the server, and a disabled item shows its `reason` in
 * words under the label. Order the groups as the page needs; the Danger group, with Cancel Visit last, goes last.
 * `showLabel` adds the word "Actions" beside the dots (for phone cards); `label` is the accessible name, for example
 * "Actions for Suria Lim". The trigger is a 44px target below 1024px; every item is 44px tall there.
 */
export type RowMenuItem = {
    id: string;
    label: string;
    allowed: boolean;
    reason?: string;
    tone?: 'default' | 'danger';
};
export type RowMenuGroup = { label: string; items: RowMenuItem[] };

defineProps<{
    label: string;
    groups: RowMenuGroup[];
    showLabel?: boolean;
}>();

const emit = defineEmits<{ select: [id: string] }>();
</script>

<template>
    <DropdownMenuRoot>
        <DropdownMenuTrigger
            data-slot="v2-row-menu"
            :aria-label="label"
            class="inline-flex h-11 min-w-11 items-center justify-center rounded-[10px] border border-border bg-card px-3 text-foreground outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background data-[state=open]:bg-muted lg:h-9"
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
            <span
                v-if="showLabel"
                class="ml-2 text-sm font-medium"
                aria-hidden="true"
                >Actions</span
            >
        </DropdownMenuTrigger>
        <DropdownMenuPortal>
            <DropdownMenuContent
                align="end"
                :side-offset="6"
                class="v2-menu z-50 max-w-[min(20rem,calc(100vw-2rem))] min-w-60 rounded-[14px] border border-border bg-popover p-1.5 text-popover-foreground dark:border-foreground/20"
            >
                <template v-for="(group, index) in groups" :key="group.label">
                    <DropdownMenuSeparator
                        v-if="index > 0"
                        class="mx-1 my-1.5 h-px bg-border"
                    />
                    <DropdownMenuLabel
                        class="px-2.5 pt-1.5 pb-1 text-xs font-medium text-muted-foreground"
                    >
                        {{ group.label }}
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="item in group.items"
                        :key="item.id"
                        :disabled="!item.allowed"
                        class="flex min-h-11 cursor-default flex-col justify-center rounded-[8px] px-2.5 py-1.5 text-sm outline-none select-none data-[disabled]:cursor-not-allowed data-[disabled]:opacity-100 data-[highlighted]:bg-muted lg:min-h-9"
                        :class="
                            item.tone === 'danger'
                                ? 'text-destructive data-[highlighted]:bg-destructive-soft'
                                : ''
                        "
                        @select="emit('select', item.id)"
                    >
                        <span
                            :class="
                                item.allowed
                                    ? 'font-medium'
                                    : 'font-medium text-muted-foreground'
                            "
                            >{{ item.label }}</span
                        >
                        <span
                            v-if="!item.allowed && item.reason"
                            class="text-xs font-normal text-muted-foreground"
                            >{{ item.reason }}</span
                        >
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
