<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * Version 2 search combobox: a named search box with a keyboard-navigable results list. Arrow keys move, Enter picks,
 * Esc closes. The `chip` slot sits inside the box at the right (for example the inferred-type chip). It holds NO
 * search or matching logic: the page passes `options` and handles `select`. `focus()` (exposed) focuses the input, for
 * example after a "Change patient" button; there is deliberately no id prop.
 */
export type ComboOption = { id: string; label: string; detail?: string };

const props = withDefaults(
    defineProps<{
        modelValue: string;
        label: string;
        options: ComboOption[];
        placeholder?: string;
        loading?: boolean;
        emptyText?: string;
    }>(),
    { placeholder: '', loading: false, emptyText: 'No matches.' },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    select: [option: ComboOption];
}>();

const input = ref<HTMLInputElement | null>(null);

function focus(): void {
    input.value?.focus();
}

defineExpose({ focus });

const base = `v2-combo-${Math.random().toString(36).slice(2, 8)}`;
const open = ref(false);
const active = ref(-1);

const showList = computed(() => open.value && props.modelValue.trim() !== '');
const activeId = computed(() =>
    active.value >= 0 ? `${base}-opt-${active.value}` : undefined,
);

const pick = (option: ComboOption) => {
    emit('select', option);
    open.value = false;
    active.value = -1;
};

const onKey = (event: KeyboardEvent) => {
    const last = props.options.length - 1;

    if (event.key === 'ArrowDown' && last >= 0) {
        event.preventDefault();
        open.value = true;
        active.value = active.value >= last ? 0 : active.value + 1;
    } else if (event.key === 'ArrowUp' && last >= 0) {
        event.preventDefault();
        open.value = true;
        active.value = active.value <= 0 ? last : active.value - 1;
    } else if (event.key === 'Enter' && showList.value && active.value >= 0) {
        event.preventDefault();
        pick(props.options[active.value]);
    } else if (event.key === 'Escape' && open.value) {
        event.preventDefault();
        open.value = false;
        active.value = -1;
    }
};
</script>

<template>
    <div data-slot="v2-search-combobox" class="relative min-w-0">
        <label class="relative block">
            <span class="sr-only">{{ label }}</span>
            <svg
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <circle cx="11" cy="11" r="7" />
                <path d="m20 20-3.5-3.5" />
            </svg>
            <input
                ref="input"
                role="combobox"
                type="text"
                autocomplete="off"
                aria-autocomplete="list"
                :aria-expanded="showList"
                :aria-controls="`${base}-list`"
                :aria-activedescendant="activeId"
                :value="modelValue"
                :placeholder="placeholder"
                class="h-11 w-full rounded-[10px] border border-input bg-card pr-28 pl-9 text-base outline-none selection:bg-primary/25 hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9"
                @input="
                    emit(
                        'update:modelValue',
                        ($event.target as HTMLInputElement).value,
                    );
                    open = true;
                    active = -1;
                "
                @focus="open = true"
                @blur="open = false"
                @keydown="onKey"
            />
            <span
                v-if="$slots.chip"
                class="absolute top-1/2 right-2 -translate-y-1/2"
                ><slot name="chip"
            /></span>
        </label>
        <ul
            v-show="showList"
            :id="`${base}-list`"
            role="listbox"
            :aria-label="label"
            class="v2-menu absolute z-20 mt-1.5 max-h-72 w-full overflow-auto rounded-[14px] border border-border bg-popover p-1.5"
        >
            <li
                v-if="loading"
                role="status"
                class="px-2.5 py-3 text-sm text-muted-foreground"
            >
                Searching…
            </li>
            <li
                v-else-if="options.length === 0"
                class="px-2.5 py-3 text-sm text-muted-foreground"
            >
                {{ emptyText }}
            </li>
            <li
                v-for="(option, index) in options"
                v-else
                :id="`${base}-opt-${index}`"
                :key="option.id"
                role="option"
                :aria-selected="index === active"
                class="flex min-h-11 cursor-default flex-col justify-center rounded-[8px] px-2.5 py-1.5 text-sm lg:min-h-9"
                :class="index === active ? 'bg-muted' : 'hover:bg-muted'"
                @mousedown.prevent="pick(option)"
            >
                <span class="font-medium">{{ option.label }}</span>
                <span
                    v-if="option.detail"
                    class="text-xs text-muted-foreground"
                    >{{ option.detail }}</span
                >
            </li>
        </ul>
    </div>
</template>
