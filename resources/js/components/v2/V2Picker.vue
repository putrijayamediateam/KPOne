<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import V2Field from './V2Field.vue';

/**
 * Version 2 picker: one field for choosing a saved record (a category, a unit, a supplier, a location, a SKU).
 *
 * The field shows the chosen value IN WORDS. Pass `selectedLabel` for the saved value, so an edit page shows it even
 * when it is not in the current results. Opening it shows a search box and a list: a popover from 640px, and below
 * 640px a bottom sheet with the search on top (so the list is not a tiny dropdown over the keyboard). Rows are 44px
 * below 1024px. The chosen row carries a check mark as well as `aria-selected`, so colour is never the only signal.
 *
 * Two sources. `options` is a local list, filtered here (case-insensitive "contains"). `searchUrl` is a server search
 * endpoint: the picker calls `GET <searchUrl>?q=<typed>` (debounced, the older call cancelled), reads a JSON array or
 * `{ data: [...] }` of `{ id, label, detail? }`, and shows at most 25. The endpoint decides what it returns and for
 * whom; the picker never asks for more than its permission allows and never widens what Classic returns.
 *
 * `required` false adds a Clear button (back to "none": emits `update:modelValue` with null).
 *
 * `allowAdd` shows a row "Add 'x'" only when the typed text matches no row exactly (case-insensitive), so near
 * duplicates are not created. Pressing it emits `add` with the typed text. The PARENT creates the item and then sets
 * `modelValue` (and `selectedLabel`). While it works pass `adding` (the row is disabled and "Adding…" is announced);
 * if it fails pass `addError` (shown in the list and announced).
 *
 * States are announced politely: "Searching…", "3 results", "No match", "Couldn't load results". Esc closes and
 * returns focus to the field. Arrow keys move, Enter picks, Home/End jump.
 *
 * A visible muted line under the search box says what the list holds ("3 results", "No match", "Searching…"); when the list stops at 25 it says "Showing the first 25. Type to narrow." Below 640px the sheet has a visible Close button. From 640px the popover opens ABOVE the field when there is no room below, and is never taller than the room it has.
 *
 * Known limit: the phone system Back gesture leaves the page (the sheet does not take over history, which would clash with Inertia's own history); Close and tapping the scrim are the ways out.
 *
 * `placeholder` is "Choose…" for every picker: a page should pass a specific word where it helps ("Choose a category").
 *
 * Wording of the label, hint and error is the page's job.
 */
export type PickerOption = { id: string; label: string; detail?: string };

const MAX = 25;

const props = withDefaults(
    defineProps<{
        modelValue: string | null;
        label: string;
        selectedLabel?: string;
        options?: PickerOption[];
        searchUrl?: string;
        placeholder?: string;
        hint?: string;
        error?: string;
        required?: boolean;
        disabled?: boolean;
        allowAdd?: boolean;
        adding?: boolean;
        addError?: string;
    }>(),
    {
        selectedLabel: undefined,
        options: () => [],
        searchUrl: undefined,
        placeholder: 'Choose…',
        hint: undefined,
        error: undefined,
        required: false,
        disabled: false,
        allowAdd: false,
        adding: false,
        addError: undefined,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string | null];
    select: [option: PickerOption];
    add: [text: string];
}>();

const base = `v2-picker-${Math.random().toString(36).slice(2, 8)}`;
const root = ref<HTMLElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);
const search = ref<HTMLInputElement | null>(null);
const open = ref(false);
const query = ref('');
const active = ref(-1);
const remote = ref<PickerOption[]>([]);
const loading = ref(false);
const failed = ref(false);
const picked = ref<PickerOption | null>(null);
const flip = ref(false);
const panelMax = ref<number | null>(null);
const wide = () =>
    typeof window !== 'undefined' &&
    window.matchMedia('(min-width: 640px)').matches;
let timer: ReturnType<typeof setTimeout> | null = null;
let controller: AbortController | null = null;

const results = computed<PickerOption[]>(() => {
    if (props.searchUrl) {
        return remote.value.slice(0, MAX);
    }

    const q = query.value.trim().toLowerCase();

    return props.options
        .filter((o) => q === '' || o.label.toLowerCase().includes(q))
        .slice(0, MAX);
});

const displayLabel = computed(() => {
    if (props.modelValue === null || props.modelValue === '') {
        return null;
    }

    if (props.selectedLabel) {
        return props.selectedLabel;
    }

    if (picked.value && picked.value.id === props.modelValue) {
        return picked.value.label;
    }

    return props.options.find((o) => o.id === props.modelValue)?.label ?? null;
});

const showAdd = computed(() => {
    const text = query.value.trim();

    if (!props.allowAdd || text === '' || loading.value || failed.value) {
        return false;
    }

    return !results.value.some(
        (o) => o.label.trim().toLowerCase() === text.toLowerCase(),
    );
});

// Rows are the results, then the Add row (index === results.length).
const rowCount = computed(() => results.value.length + (showAdd.value ? 1 : 0));

// The endpoint (or the local filter) stops at 25: exactly 25 rows may mean there are more.
const capped = computed(() =>
    props.searchUrl
        ? remote.value.length >= MAX
        : props.options.filter(
              (o) =>
                  query.value.trim() === '' ||
                  o.label
                      .toLowerCase()
                      .includes(query.value.trim().toLowerCase()),
          ).length > MAX,
);

const status = computed(() => {
    if (props.adding) {
        return 'Adding…';
    }

    if (props.addError) {
        return props.addError;
    }

    if (loading.value) {
        return 'Searching…';
    }

    if (failed.value) {
        return "Couldn't load results.";
    }

    const n = results.value.length;

    if (capped.value) {
        return `Showing the first ${MAX}. Type to narrow.`;
    }

    return n === 0 ? 'No match' : n === 1 ? '1 result' : `${n} results`;
});

const load = async () => {
    if (!props.searchUrl) {
        return;
    }

    controller?.abort();
    controller = new AbortController();
    loading.value = true;
    failed.value = false;

    try {
        const sep = props.searchUrl.includes('?') ? '&' : '?';
        const response = await fetch(
            `${props.searchUrl}${sep}q=${encodeURIComponent(query.value.trim())}`,
            {
                signal: controller.signal,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            },
        );

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const body = await response.json();
        remote.value = (Array.isArray(body) ? body : (body.data ?? [])).slice(
            0,
            MAX,
        );
        loading.value = false;
    } catch (e) {
        if ((e as Error).name === 'AbortError') {
            return;
        }

        remote.value = [];
        failed.value = true;
        loading.value = false;
    }
};

watch(query, () => {
    active.value = -1;

    if (!props.searchUrl) {
        return;
    }

    if (timer) {
        clearTimeout(timer);
    }

    loading.value = true;
    timer = setTimeout(load, 250);
});

// The parent creates an item from "Add 'x'" and then sets modelValue: that finishes the choice, so close the list.
watch(
    () => props.modelValue,
    () => {
        if (open.value) {
            close(true);
        }
    },
);

const close = (returnFocus: boolean) => {
    if (timer) {
        clearTimeout(timer);
    }

    controller?.abort();
    loading.value = false;
    open.value = false;
    active.value = -1;

    if (returnFocus) {
        nextTick(() => trigger.value?.focus());
    }
};

const openList = () => {
    if (props.disabled) {
        return;
    }

    query.value = '';

    // From 640px the list is a popover: open it above the field when there is no room below, and never taller than
    // the room it has, so it cannot run off the screen.
    flip.value = false;
    panelMax.value = null;

    if (wide() && trigger.value) {
        const r = trigger.value.getBoundingClientRect();
        const below = window.innerHeight - r.bottom - 12;
        const above = r.top - 12;

        flip.value = below < 260 && above > below;
        panelMax.value = Math.max(
            160,
            Math.min(320, flip.value ? above : below),
        );
    }

    open.value = true;
    active.value = -1;
    load();
    nextTick(() => search.value?.focus());
};

const pick = (option: PickerOption) => {
    picked.value = option;
    emit('select', option);
    emit('update:modelValue', option.id);
    close(true);
};

const add = () => {
    if (props.adding) {
        return;
    }

    emit('add', query.value.trim());
};

const activate = (index: number) => {
    if (index < results.value.length) {
        pick(results.value[index]);
    } else if (showAdd.value) {
        add();
    }
};

const clear = () => {
    picked.value = null;
    emit('update:modelValue', null);
    nextTick(() => trigger.value?.focus());
};

const onKey = (event: KeyboardEvent) => {
    const last = rowCount.value - 1;

    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        close(true);
    } else if (event.key === 'ArrowDown' && last >= 0) {
        event.preventDefault();
        active.value = active.value >= last ? 0 : active.value + 1;
    } else if (event.key === 'ArrowUp' && last >= 0) {
        event.preventDefault();
        active.value = active.value <= 0 ? last : active.value - 1;
    } else if (event.key === 'Home' && last >= 0 && active.value >= 0) {
        event.preventDefault();
        active.value = 0;
    } else if (event.key === 'End' && last >= 0 && active.value >= 0) {
        event.preventDefault();
        active.value = last;
    } else if (event.key === 'Enter') {
        event.preventDefault();

        if (active.value >= 0) {
            activate(active.value);
        }
    }
};

const onFocusOut = (event: FocusEvent) => {
    const next = event.relatedTarget as Node | null;

    if (open.value && next && !root.value?.contains(next)) {
        close(false);
    }
};

onBeforeUnmount(() => {
    if (timer) {
        clearTimeout(timer);
    }

    controller?.abort();
});
</script>

<template>
    <V2Field
        v-slot="{ id, describedBy, invalid }"
        :label="label"
        :hint="hint"
        :error="error"
        :required="required"
    >
        <div
            ref="root"
            data-slot="v2-picker"
            class="relative min-w-0"
            @focusout="onFocusOut"
        >
            <div class="flex gap-2">
                <button
                    :id="id"
                    ref="trigger"
                    type="button"
                    :disabled="disabled"
                    aria-haspopup="listbox"
                    :aria-expanded="open"
                    :aria-controls="`${base}-list`"
                    :aria-describedby="describedBy"
                    :aria-invalid="invalid || undefined"
                    class="flex h-11 min-w-0 flex-1 items-center justify-between gap-2 rounded-[10px] border bg-card px-3 text-left text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-60 md:text-[15px] lg:h-9"
                    :class="invalid ? 'border-destructive' : 'border-input'"
                    @click="open ? close(true) : openList()"
                >
                    <span
                        class="min-w-0 truncate"
                        :class="displayLabel ? '' : 'text-muted-foreground'"
                        >{{ displayLabel ?? placeholder }}</span
                    >
                    <svg
                        class="size-4 shrink-0 text-muted-foreground"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="m6 9 6 6 6-6" />
                    </svg>
                </button>
                <button
                    v-if="!required && displayLabel && !disabled"
                    type="button"
                    :aria-label="`Clear ${label}`"
                    class="inline-flex size-11 shrink-0 items-center justify-center rounded-[10px] border border-input bg-card outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring lg:size-9"
                    @click="clear"
                >
                    <svg
                        class="size-4"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.25"
                        stroke-linecap="round"
                        aria-hidden="true"
                    >
                        <path d="M6 6l12 12M18 6 6 18" />
                    </svg>
                </button>
            </div>

            <p class="sr-only" role="status" aria-live="polite">
                {{ open ? status : '' }}
            </p>

            <!-- Phone: a scrim behind the sheet. Tapping it closes. -->
            <div
                v-if="open"
                class="fixed inset-0 z-40 bg-black/50 sm:hidden"
                aria-hidden="true"
                @click="close(true)"
            />
            <div
                v-if="open"
                data-slot="v2-picker-panel"
                role="dialog"
                :aria-label="label"
                class="v2-menu fixed inset-x-0 bottom-0 z-50 flex max-h-[80dvh] flex-col rounded-t-[18px] border border-border bg-popover p-3 sm:absolute sm:inset-x-auto sm:left-0 sm:max-h-[var(--v2-picker-max,20rem)] sm:w-full sm:rounded-[14px] sm:p-1.5"
                :class="
                    flip
                        ? 'sm:top-auto sm:bottom-full sm:mb-1.5'
                        : 'sm:top-full sm:bottom-auto sm:mt-1.5'
                "
                :style="
                    panelMax !== null
                        ? { '--v2-picker-max': `${panelMax}px` }
                        : undefined
                "
                @keydown="onKey"
            >
                <div class="mb-1.5 flex items-center justify-between sm:hidden">
                    <span class="px-1 text-base font-semibold">{{
                        label
                    }}</span>
                    <button
                        type="button"
                        class="inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                        @click="close(true)"
                    >
                        Close
                    </button>
                </div>
                <input
                    ref="search"
                    v-model="query"
                    type="text"
                    role="combobox"
                    autocomplete="off"
                    aria-autocomplete="list"
                    aria-expanded="true"
                    :aria-controls="`${base}-list`"
                    :aria-activedescendant="
                        active >= 0 ? `${base}-opt-${active}` : undefined
                    "
                    :aria-label="`Search ${label}`"
                    placeholder="Search…"
                    class="mb-1.5 h-11 w-full shrink-0 rounded-[10px] border border-input bg-card px-3 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9"
                />
                <p
                    class="px-1 pb-1.5 text-[13px] text-muted-foreground"
                    aria-hidden="true"
                    data-slot="v2-picker-count"
                >
                    {{ status }}
                </p>
                <ul
                    :id="`${base}-list`"
                    role="listbox"
                    :aria-label="label"
                    class="min-h-0 flex-1 overflow-auto"
                >
                    <li
                        v-for="(option, index) in results"
                        :id="`${base}-opt-${index}`"
                        :key="option.id"
                        role="option"
                        :aria-selected="option.id === modelValue"
                        class="flex min-h-11 cursor-default items-center justify-between gap-2 rounded-[8px] px-2.5 py-1.5 text-sm lg:min-h-9"
                        :class="
                            index === active ? 'bg-muted' : 'hover:bg-muted'
                        "
                        @mousedown.prevent="pick(option)"
                    >
                        <span class="flex min-w-0 flex-col">
                            <span class="font-medium">{{ option.label }}</span>
                            <span
                                v-if="option.detail"
                                class="text-xs text-muted-foreground"
                                >{{ option.detail }}</span
                            >
                        </span>
                        <svg
                            v-if="option.id === modelValue"
                            class="size-4 shrink-0"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <path d="m5 12 5 5 9-10" />
                        </svg>
                    </li>
                    <li
                        v-if="showAdd"
                        :id="`${base}-opt-${results.length}`"
                        role="option"
                        :aria-selected="false"
                        :aria-disabled="adding || undefined"
                        class="flex min-h-11 items-center rounded-[8px] px-2.5 py-1.5 text-sm font-medium text-primary lg:min-h-9"
                        :class="[
                            active === results.length
                                ? 'bg-muted'
                                : 'hover:bg-muted',
                            adding
                                ? 'cursor-not-allowed opacity-60'
                                : 'cursor-default',
                        ]"
                        @mousedown.prevent="add"
                    >
                        {{ adding ? 'Adding…' : `Add '${query.trim()}'` }}
                    </li>
                </ul>
                <p
                    v-if="
                        !loading && !failed && results.length === 0 && !showAdd
                    "
                    class="px-2.5 py-3 text-sm text-muted-foreground"
                >
                    No match
                </p>
                <p
                    v-if="loading"
                    class="px-2.5 py-3 text-sm text-muted-foreground"
                >
                    Searching…
                </p>
                <div
                    v-if="failed"
                    class="flex items-center justify-between gap-2 px-2.5 py-2 text-sm text-destructive"
                >
                    <span>Couldn't load results.</span>
                    <button
                        type="button"
                        class="inline-flex h-11 items-center rounded-[10px] border border-border bg-card px-3 font-medium text-foreground outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring lg:h-9"
                        @click="load"
                    >
                        Try again
                    </button>
                </div>
                <p v-if="addError" class="px-2.5 py-2 text-sm text-destructive">
                    {{ addError }}
                </p>
            </div>
        </div>
    </V2Field>
</template>
