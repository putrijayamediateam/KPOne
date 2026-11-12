<script setup lang="ts">
import { nextTick, onMounted, ref, watch } from 'vue';

/**
 * Version 2 tabs: a full-pill segmented control. The selected tab is scrolled into view; the `trailing` slot sits at
 * the right end (for example the live-refresh note). Arrow keys, Home and End move between tabs.
 */
type Tab = { value: string; label: string; count?: number; disabled?: boolean };

const props = defineProps<{
    modelValue: string;
    tabs: Tab[];
    ariaLabel: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const list = ref<HTMLElement | null>(null);

const reveal = () =>
    nextTick(() =>
        list.value
            ?.querySelector<HTMLElement>('[aria-selected="true"]')
            ?.scrollIntoView({ block: 'nearest', inline: 'nearest' }),
    );

onMounted(reveal);
watch(() => props.modelValue, reveal);

const move = (event: KeyboardEvent, index: number) => {
    const enabled = props.tabs
        .map((t, i) => ({ t, i }))
        .filter(({ t }) => !t.disabled);
    const at = enabled.findIndex(({ i }) => i === index);
    const next =
        event.key === 'ArrowRight'
            ? enabled[(at + 1) % enabled.length]
            : event.key === 'ArrowLeft'
              ? enabled[(at - 1 + enabled.length) % enabled.length]
              : event.key === 'Home'
                ? enabled[0]
                : event.key === 'End'
                  ? enabled[enabled.length - 1]
                  : null;

    if (!next) {
        return;
    }

    event.preventDefault();
    emit('update:modelValue', next.t.value);
    nextTick(() =>
        list.value
            ?.querySelectorAll<HTMLElement>('[role=tab]')
            [next.i]?.focus(),
    );
};
</script>

<template>
    <div
        data-slot="v2-tabs"
        class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:gap-3"
    >
        <div
            ref="list"
            role="tablist"
            :aria-label="ariaLabel"
            class="flex min-w-0 [scrollbar-width:none] gap-1 overflow-x-auto rounded-full bg-muted p-1 [&::-webkit-scrollbar]:hidden"
        >
            <button
                v-for="(tab, index) in tabs"
                :key="tab.value"
                type="button"
                role="tab"
                :aria-selected="tab.value === modelValue"
                :tabindex="tab.value === modelValue ? 0 : -1"
                :disabled="tab.disabled"
                class="inline-flex h-11 shrink-0 items-center gap-1.5 rounded-full px-4 text-sm whitespace-nowrap transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50 motion-reduce:transition-none lg:h-9"
                :class="
                    tab.value === modelValue
                        ? 'bg-card font-medium text-primary ring-1 ring-border dark:bg-foreground/8'
                        : 'text-muted-foreground hover:text-foreground'
                "
                @click="emit('update:modelValue', tab.value)"
                @keydown="move($event, index)"
            >
                {{ tab.label }}
                <span
                    v-if="tab.count !== undefined"
                    class="text-xs text-muted-foreground tabular-nums"
                    >{{ tab.count }}</span
                >
            </button>
        </div>
        <div
            v-if="$slots.trailing"
            class="shrink-0 text-[13px] text-muted-foreground sm:ml-auto"
        >
            <slot name="trailing" />
        </div>
    </div>
</template>
