<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuPortal,
    DropdownMenuRoot,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from 'reka-ui';
import { ref, watch } from 'vue';

/**
 * Version 2 row menu: the "…" popover with grouped items (Open, Move, Call, Money, Danger). It holds NO permission
 * logic: the page passes each item's `allowed` state from the server, and a disabled item shows its `reason` in
 * words under the label. Order the groups as the page needs; the Danger group, with Cancel Visit last, goes last.
 * An item with an `href` is a real link (Inertia `Link`), so middle-click, Ctrl/Cmd-click and "open in new tab" work;
 * items without one emit `select`. `busy` shows a spinner on the trigger, names it "Working…", closes the menu and
 * disables it while a row action runs. `showLabel` adds the word "Actions" beside the dots (for phone cards); `label` is the accessible name, for example
 * "Actions for Suria Lim". The trigger is a 44px target below 1024px; every item is 44px tall there.
 */
export type RowMenuItem = {
    id: string;
    label: string;
    allowed: boolean;
    reason?: string;
    tone?: 'default' | 'danger';
    href?: string;
};
/** `label` may be '' for a one-group menu: no heading is rendered then. */
export type RowMenuGroup = { label: string; items: RowMenuItem[] };

const props = defineProps<{
    label: string;
    groups: RowMenuGroup[];
    showLabel?: boolean;
    busy?: boolean;
}>();

const emit = defineEmits<{ select: [id: string] }>();

// The open state is kept here, so a menu that was closed by `busy` opens again once `busy` ends. (Binding `:open` to
// `busy ? false : undefined` left the menu controlled forever after the first busy, and it never opened again.)
const open = ref(false);

watch(
    () => props.busy,
    (busy) => {
        if (busy) {
            open.value = false;
        }
    },
);
</script>

<template>
    <DropdownMenuRoot
        :open="open && !busy"
        @update:open="(value: boolean) => (open = value)"
    >
        <DropdownMenuTrigger
            data-slot="v2-row-menu"
            :aria-label="busy ? 'Working…' : label"
            :aria-busy="busy"
            :disabled="busy"
            class="inline-flex h-11 min-w-11 items-center justify-center rounded-[10px] border border-border bg-card px-3 text-foreground outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-wait disabled:opacity-70 data-[state=open]:bg-muted lg:h-9"
        >
            <svg
                v-if="busy"
                class="size-4 animate-spin motion-reduce:animate-none"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2.5"
                stroke-linecap="round"
                aria-hidden="true"
            >
                <path d="M12 3a9 9 0 1 0 9 9" />
            </svg>
            <svg
                v-else
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
                <template
                    v-for="(group, index) in groups"
                    :key="`${index}-${group.label}`"
                >
                    <DropdownMenuSeparator
                        v-if="index > 0"
                        class="mx-1 my-1.5 h-px bg-border"
                    />
                    <DropdownMenuLabel
                        v-if="group.label"
                        class="px-2.5 pt-1.5 pb-1 text-xs font-medium text-muted-foreground"
                    >
                        {{ group.label }}
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="item in group.items"
                        :key="item.id"
                        :disabled="!item.allowed"
                        :as-child="!!(item.href && item.allowed)"
                        class="flex min-h-11 cursor-default flex-col justify-center rounded-[8px] px-2.5 py-1.5 text-sm outline-none select-none data-[disabled]:cursor-not-allowed data-[disabled]:opacity-100 data-[highlighted]:bg-muted lg:min-h-9"
                        :class="
                            item.tone === 'danger'
                                ? 'text-destructive data-[highlighted]:bg-destructive-soft'
                                : ''
                        "
                        @select="!item.href && emit('select', item.id)"
                    >
                        <Link
                            v-if="item.href && item.allowed"
                            :href="item.href"
                            class="flex min-h-11 flex-col justify-center px-2.5 py-1.5 text-sm font-medium lg:min-h-9"
                        >
                            {{ item.label }}
                        </Link>
                        <template v-else>
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
                        </template>
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
