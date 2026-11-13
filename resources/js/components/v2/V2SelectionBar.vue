<script setup lang="ts">
import {
    computed,
    inject,
    onBeforeUnmount,
    onMounted,
    ref,
    useSlots,
    watch,
} from 'vue';
import { V2_RAIL_FROM } from './railKey';
import type { RailFrom } from './railKey';

/**
 * Version 2 selection bar: a bar stuck to the foot of the main column whenever something is ticked and the page's
 * right rail is not showing. It carries the summary ("3 claims selected · Panel X · RM 1,240.00"), the one filled
 * action and an optional outline secondary (for example "Clear").
 *
 * Rail breakpoint: V2WorkLayout provides its own `railFrom` to everything inside it, and the bar reads it, so a page
 * never repeats it and the rail and the bar can never both disappear. The `railFrom` prop is only an override for a bar
 * placed outside a V2WorkLayout. From that width up the bar is hidden; a layout with no rail never hides it.
 *
 * Rail showing means NOT rendered: from the rail width up the bar is removed from the page (a media query watches it), so
 * it neither shows nor announces anything while the rail carries the summary and actions.
 *
 * `visible` is the page's own condition (usually "something is ticked"). `actionDisabled` needs a reason: give `hint`
 * (text, or the `hint` slot) and it shows beside the summary ("Choose at most 20 invoices"); a disabled action with no
 * hint warns in development. `hintTone` says what kind of message the hint is: `neutral` (muted text), `warning` (a
 * warning notice, announced politely, warning colour with the word "Warning:" before it) or `error` (an error,
 * announced at once with role=alert, destructive colour with the word "Error:" before it). `busy` keeps the label, shows a spinner, disables both buttons and sets `aria-busy`; the action keeps its full-strength
 * fill and readable label (only a disabled action and Clear dim), because busy is progress, not unavailable.
 *
 * Announcements (one polite live region that stays mounted): `announce` (default: the summary) whenever it changes while
 * the bar is shown; `clearedMessage` ("Selection cleared") once when the bar goes away; nothing when it was never
 * rendered. Set `clearedMessage` to '' for a page that announces its own result. Moving focus after Clear is the page's
 * job (the `secondary` event).
 *
 * Layout: the bar is `sticky` at the end of the main column, so it takes its own space in the flow and the last row is
 * never behind it (place it as the last child of that column; the wrapper is `display: contents` so it can stick). It
 * sits under dialogs and popover menus (z-10 against their z-50). 44px targets below 1024px (36px from 1024px),
 * safe-area padding, the focus ring with an offset. No selection or action logic lives here.
 */
const props = withDefaults(
    defineProps<{
        visible: boolean;
        summary: string;
        actionLabel: string;
        railFrom?: RailFrom;
        secondaryLabel?: string;
        announce?: string;
        clearedMessage?: string;
        hint?: string;
        hintTone?: 'neutral' | 'warning' | 'error';
        actionDisabled?: boolean;
        busy?: boolean;
    }>(),
    {
        railFrom: undefined,
        secondaryLabel: undefined,
        announce: undefined,
        clearedMessage: 'Selection cleared',
        hint: undefined,
        hintTone: 'neutral',
        actionDisabled: false,
        busy: false,
    },
);

const emit = defineEmits<{ action: []; secondary: [] }>();
const slots = useSlots();
const layoutRailFrom = inject(V2_RAIL_FROM, null);

// The prop wins; inside a V2WorkLayout its value is used (null means the page has no rail, so the bar never hides);
// outside a layout the default is 2xl.
const effectiveRailFrom = computed<RailFrom | null>(() => {
    if (props.railFrom) {
        return props.railFrom;
    }

    return layoutRailFrom ? layoutRailFrom.value : '2xl';
});
const MIN_WIDTH: Record<RailFrom, string> = {
    lg: '(min-width: 64rem)',
    xl: '(min-width: 80rem)',
    '2xl': '(min-width: 96rem)',
};
const railShowing = ref(false);
let media: MediaQueryList | null = null;
const syncRail = () => (railShowing.value = !!media && media.matches);
const watchRail = () => {
    media?.removeEventListener('change', syncRail);
    media =
        effectiveRailFrom.value && typeof window !== 'undefined'
            ? window.matchMedia(MIN_WIDTH[effectiveRailFrom.value])
            : null;
    media?.addEventListener('change', syncRail);
    syncRail();
};

onMounted(watchRail);
watch(effectiveRailFrom, watchRail);
onBeforeUnmount(() => media?.removeEventListener('change', syncRail));

// Shown only while the page wants it AND the rail is not showing; otherwise nothing is rendered or announced.
const shown = computed(() => props.visible && !railShowing.value);

const hasHint = computed(() => Boolean(props.hint) || Boolean(slots.hint));

if (import.meta.env?.DEV) {
    watch(
        () => [props.visible, props.actionDisabled, hasHint.value],
        ([visible, disabled, hint]) => {
            if (visible && disabled && !hint) {
                console.warn(
                    'V2SelectionBar: a disabled action needs a `hint` that says why.',
                );
            }
        },
        { immediate: true },
    );
}

const clearedNote = ref('');
let clearTimer: ReturnType<typeof setTimeout> | null = null;

watch(shown, (now, before) => {
    if (before && !now && !props.visible && props.clearedMessage) {
        clearedNote.value = props.clearedMessage;

        if (clearTimer) {
            clearTimeout(clearTimer);
        }

        clearTimer = setTimeout(() => (clearedNote.value = ''), 2000);
    } else if (now) {
        clearedNote.value = '';
    }
});
onBeforeUnmount(() => clearTimer && clearTimeout(clearTimer));

const spoken = computed(() =>
    shown.value ? (props.announce ?? props.summary) : clearedNote.value,
);

const ring =
    'outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background';
</script>

<template>
    <div data-slot="v2-selection-bar-region" class="contents">
        <div class="sr-only" role="status" aria-live="polite">
            {{ spoken }}
        </div>
        <div
            v-if="shown"
            data-slot="v2-selection-bar"
            class="sticky bottom-0 z-10 -mx-4 flex flex-col gap-3 border-t bg-background px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:flex-row sm:items-center sm:justify-between"
            :aria-busy="busy"
        >
            <div class="min-w-0">
                <p class="text-[15px] leading-[22px] font-medium">
                    <slot name="summary">{{ summary }}</slot>
                </p>
                <p
                    v-if="hasHint"
                    class="text-sm leading-5"
                    :class="
                        hintTone === 'error'
                            ? 'text-destructive'
                            : hintTone === 'warning'
                              ? 'text-warning-strong'
                              : 'text-muted-foreground'
                    "
                    :role="
                        hintTone === 'error'
                            ? 'alert'
                            : hintTone === 'warning'
                              ? 'status'
                              : undefined
                    "
                    data-slot="v2-selection-bar-hint"
                >
                    <span v-if="hintTone === 'error'" class="font-medium"
                        >Error:
                    </span>
                    <span v-else-if="hintTone === 'warning'" class="font-medium"
                        >Warning:
                    </span>
                    <slot name="hint">{{ hint }}</slot>
                </p>
            </div>
            <div class="flex shrink-0 gap-2 max-sm:w-full [&>*]:max-sm:flex-1">
                <button
                    v-if="secondaryLabel"
                    type="button"
                    :disabled="busy"
                    class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50 lg:h-9"
                    :class="ring"
                    @click="emit('secondary')"
                >
                    {{ secondaryLabel }}
                </button>
                <button
                    type="button"
                    :disabled="actionDisabled || busy"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 lg:h-9"
                    :class="[
                        ring,
                        actionDisabled && !busy
                            ? 'cursor-not-allowed opacity-50'
                            : '',
                        busy ? 'cursor-progress' : '',
                    ]"
                    @click="emit('action')"
                >
                    <svg
                        v-if="busy"
                        class="size-4 animate-spin motion-reduce:animate-none"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        aria-hidden="true"
                    >
                        <path d="M21 12a9 9 0 1 1-6.2-8.55" />
                    </svg>
                    {{ actionLabel }}
                </button>
            </div>
        </div>
    </div>
</template>
