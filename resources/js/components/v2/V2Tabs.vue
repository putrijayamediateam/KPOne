<script lang="ts">
// A page whose tabs are links (a visit that rebuilds the whole page) builds a new strip after the key press, so the focus the
// key press moved is gone. The strip that was used remembers which tab was chosen by keyboard, and the new strip takes the
// focus back for that tab. It expires after a few seconds so a later, unrelated strip never steals focus.
let keyboardChoice: { label: string; value: string; at: number } | null = null;
const KEYBOARD_FOCUS_MS = 4000;
</script>

<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Version 2 tabs: a full-pill segmented control. When the strip scrolls, the selected tab is brought to the middle of it on load
 * and on every change (smooth, instant with reduced motion; only the strip scrolls, never the page); the `trailing` slot sits at
 * the right end (for example the live-refresh note). Arrow keys, Home and End move between tabs. The tabs only pick a
 * value: the page owns the tabpanel (role="tabpanel", its id and aria-labelledby) and the `aria-controls` on the tabs.
 */
type Tab = { value: string; label: string; count?: number; disabled?: boolean };

const props = defineProps<{
    modelValue: string;
    tabs: Tab[];
    ariaLabel: string;
    /** Optional: gives each tab `id="{prefix}-tab-{value}"` and `aria-controls="{prefix}-panel-{value}"` for the page's tabpanels. */
    idPrefix?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const list = ref<HTMLElement | null>(null);
const more = ref(false);
const before = ref(false);
let observer: ResizeObserver | null = null;

// An edge fade on each side while more tabs are hidden there, so a cut-off tab is never a surprise (the left one shows
// when the strip opens scrolled to the selected tab, so the first tab is not left looking like a stray cut-off word).
const measure = () => {
    const el = list.value;

    before.value = !!el && el.scrollLeft > 1;
    more.value = !!el && el.scrollWidth - el.clientWidth - el.scrollLeft > 1;
};

// Bring the selected tab to the middle of a strip that scrolls (on load and on every change), so it is never cut off or
// sitting under the right-edge fade. Only the strip moves, never the page. Smooth, unless the person asked for less motion.
const reveal = () =>
    nextTick(() => {
        const el = list.value;
        const tab = el?.querySelector<HTMLElement>('[aria-selected="true"]');

        if (!el || !tab || el.scrollWidth <= el.clientWidth) {
            return;
        }

        const reduced = window.matchMedia(
            '(prefers-reduced-motion: reduce)',
        ).matches;
        const left = tab.offsetLeft - (el.clientWidth - tab.offsetWidth) / 2;

        el.scrollTo({
            left: Math.max(0, Math.min(left, el.scrollWidth - el.clientWidth)),
            behavior: reduced ? 'auto' : 'smooth',
        });
    });

onMounted(() => {
    reveal();
    measure();

    const chosen = keyboardChoice;

    keyboardChoice = null;

    if (
        chosen &&
        chosen.label === props.ariaLabel &&
        chosen.value === props.modelValue &&
        Date.now() - chosen.at < KEYBOARD_FOCUS_MS
    ) {
        nextTick(() =>
            list.value
                ?.querySelector<HTMLElement>('[role=tab][aria-selected="true"]')
                ?.focus({ preventScroll: true }),
        );
    }

    if (typeof ResizeObserver !== 'undefined' && list.value) {
        observer = new ResizeObserver(measure);
        observer.observe(list.value);
    }
});
onBeforeUnmount(() => observer?.disconnect());
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
    keyboardChoice = {
        label: props.ariaLabel,
        value: next.t.value,
        at: Date.now(),
    };
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
            :class="
                more && before
                    ? '[mask-image:linear-gradient(to_right,transparent,#000_2.5rem,#000_calc(100%-2.5rem),transparent)]'
                    : more
                      ? '[mask-image:linear-gradient(to_right,#000_calc(100%-2.5rem),transparent)]'
                      : before
                        ? '[mask-image:linear-gradient(to_right,transparent,#000_2.5rem)]'
                        : ''
            "
            @scroll.passive="measure"
        >
            <button
                v-for="(tab, index) in tabs"
                :key="tab.value"
                type="button"
                role="tab"
                :id="idPrefix ? `${idPrefix}-tab-${tab.value}` : undefined"
                :aria-controls="
                    idPrefix ? `${idPrefix}-panel-${tab.value}` : undefined
                "
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
