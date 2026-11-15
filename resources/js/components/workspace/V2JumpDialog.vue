<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import type { V2Shell } from '@/types';

/**
 * UX-17: "Search or jump to…". A jump list only (B.E.R.N.I.S.S, 2026-11-12): it filters the spaces and "More" pages
 * the server already sent in `workspace.shell`. No search endpoint and no patient search. The dialog traps focus and
 * Escape closes it (reka-ui Dialog).
 */
const props = defineProps<{ shell: V2Shell }>();
const open = defineModel<boolean>('open', { default: false });

const query = ref('');
const active = ref(0);

type Entry = { title: string; group: string; href: string };

const entries = computed<Entry[]>(() => [
    ...props.shell.groups.flatMap((group) =>
        group.items.flatMap((item) => [
            { title: item.title, group: group.title, href: item.href },
            ...item.links.map((link) => ({
                title: link.title,
                group: item.title,
                href: link.href,
            })),
        ]),
    ),
    ...props.shell.more.flatMap((section) =>
        section.links.map((link) => ({
            title: link.title,
            group: `More · ${section.title}`,
            href: link.href,
        })),
    ),
]);

const results = computed(() => {
    const words = query.value.trim().toLowerCase().split(/\s+/).filter(Boolean);

    return entries.value.filter((entry) => {
        const text = `${entry.title} ${entry.group}`.toLowerCase();

        return words.every((word) => text.includes(word));
    });
});

watch(query, () => (active.value = 0));
watch(open, (value) => {
    if (value) {
        query.value = '';
        active.value = 0;
    }
});

const go = (entry: Entry | undefined) => {
    if (!entry) {
        return;
    }

    open.value = false;
    router.visit(entry.href);
};

const move = (step: number) => {
    const count = results.value.length;

    if (count > 0) {
        active.value = (active.value + step + count) % count;
        void nextTick(() =>
            document
                .getElementById(`v2-jump-option-${active.value}`)
                ?.scrollIntoView({ block: 'nearest' }),
        );
    }
};

// Enter while an input method is still composing a word must not navigate.
const enter = (event: KeyboardEvent) => {
    if (!event.isComposing) {
        go(results.value[active.value]);
    }
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="top-[15%] translate-y-0 gap-0 p-0 motion-reduce:transition-none motion-reduce:data-[state=closed]:animate-none motion-reduce:data-[state=open]:animate-none sm:max-w-lg"
            data-test="v2-jump-dialog"
        >
            <DialogTitle class="sr-only">Jump to a page</DialogTitle>
            <DialogDescription class="sr-only">
                Type to filter the spaces and pages you can open. Use the arrow
                keys and Enter to go.
            </DialogDescription>
            <div class="flex items-center gap-2 border-b pr-11 pl-3">
                <Search
                    class="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <input
                    v-model="query"
                    type="text"
                    role="combobox"
                    aria-label="Search or jump to"
                    aria-controls="v2-jump-results"
                    :aria-expanded="results.length > 0"
                    aria-autocomplete="list"
                    :aria-activedescendant="
                        results.length > 0
                            ? `v2-jump-option-${active}`
                            : undefined
                    "
                    placeholder="Search or jump to…"
                    autocomplete="off"
                    class="h-12 min-w-0 flex-1 bg-transparent text-sm outline-none"
                    @keydown.down.prevent="move(1)"
                    @keydown.up.prevent="move(-1)"
                    @keydown.enter.prevent="enter"
                />
            </div>
            <ul
                id="v2-jump-results"
                role="listbox"
                aria-label="Pages"
                class="max-h-80 overflow-y-auto p-1.5"
            >
                <li
                    v-for="(entry, index) in results"
                    :id="`v2-jump-option-${index}`"
                    :key="`${entry.href}|${entry.title}`"
                    role="option"
                    :aria-selected="index === active"
                    class="flex min-h-11 cursor-pointer flex-col justify-center rounded-lg px-3 py-1.5"
                    :class="{ 'bg-muted': index === active }"
                    @mousemove="active = index"
                    @click="go(entry)"
                >
                    <span class="text-sm">{{ entry.title }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        entry.group
                    }}</span>
                </li>
            </ul>
            <p
                class="px-3 text-center text-sm text-muted-foreground"
                :class="{ 'pb-6': results.length === 0 }"
                role="status"
            >
                <template v-if="results.length === 0"
                    >No page matches “{{ query.trim() }}”.</template
                >
            </p>
        </DialogContent>
    </Dialog>
</template>
