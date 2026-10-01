<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { JsonRequestError, requestJson } from '@/lib/json-client';

type Option = { value: string; label: string };

const props = withDefaults(
    defineProps<{
        modelValue: string;
        type: string;
        label: string;
        error?: string;
        disabled?: boolean;
    }>(),
    { error: '', disabled: false },
);
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const query = ref(props.modelValue);
const results = ref<Option[]>([]);
const open = ref(false);
const loading = ref(false);
const adding = ref(false);
const errorMessage = ref('');
const activeIndex = ref(-1);
const listboxId = `catalogue-options-${useId()}`;
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
const requestErrorMessage = (error: unknown, action: 'load' | 'save') => {
    if (error instanceof JsonRequestError) {
        if (error.status === 401) {
            return 'Your session has expired. Sign in again and retry.';
        }

        if (error.status === 403) {
            return 'Your account is not allowed to manage these options.';
        }

        if (error.status === 419) {
            return 'Your session security token expired. Refresh the page and retry.';
        }

        if (error.status === 429) {
            return 'Too many requests. Wait a moment and retry.';
        }

        if (error.status >= 500) {
            return `Options could not be ${action === 'load' ? 'loaded' : 'saved'} right now. Try again, and contact an administrator if this continues.`;
        }
    }

    return action === 'load'
        ? 'Could not connect to load saved options. Check your connection and retry.'
        : 'Could not connect to save this option. Check your connection and retry.';
};
const normalizedQuery = computed(() =>
    query.value.trim().replace(/\s+/g, ' ').toLocaleLowerCase(),
);
const exactMatch = computed(
    () =>
        results.value.find(
            (option) =>
                option.label.trim().replace(/\s+/g, ' ').toLocaleLowerCase() ===
                normalizedQuery.value,
        ) ?? null,
);
const canAdd = computed(
    () =>
        normalizedQuery.value.length > 0 &&
        !exactMatch.value &&
        normalizedQuery.value.length <= 200,
);

watch(
    () => props.modelValue,
    (value) => {
        if (!open.value) {
            query.value = value;
        }
    },
);

const search = async () => {
    controller?.abort();
    const searchTerm = query.value.trim();
    const current = new AbortController();
    controller = current;
    loading.value = true;
    errorMessage.value = '';

    try {
        const params = new URLSearchParams();

        if (searchTerm) {
            params.set('query', searchTerm);
        }

        const payload = await requestJson<{ data: Option[] }>(
            `/catalogue-options/${props.type}?${params.toString()}`,
            { signal: current.signal },
        );
        results.value = payload.data;
        activeIndex.value = results.value.length ? 0 : -1;
        open.value = true;
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            return;
        }

        errorMessage.value = requestErrorMessage(error, 'load');
    } finally {
        if (controller === current) {
            loading.value = false;
        }
    }
};

watch(query, () => {
    controller?.abort();

    if (timer) {
        clearTimeout(timer);
    }

    open.value = false;
    activeIndex.value = -1;
    timer = setTimeout(() => void search(), 150);
});

onBeforeUnmount(() => {
    if (timer) {
        clearTimeout(timer);
    }

    controller?.abort();
});

const select = (option: Option) => {
    emit('update:modelValue', option.value);
    query.value = option.label;
    open.value = false;
    errorMessage.value = '';
};

const toggleOptions = () => {
    if (open.value) {
        controller?.abort();
        open.value = false;
        loading.value = false;
        activeIndex.value = -1;

        return;
    }

    void search();
};

const closeOptions = () => {
    controller?.abort();
    open.value = false;
    loading.value = false;
    activeIndex.value = -1;
};

const add = async () => {
    if (!canAdd.value || adding.value) {
        return;
    }

    adding.value = true;
    errorMessage.value = '';

    try {
        const payload = await requestJson<{ data: Option }>(
            `/catalogue-options/${props.type}`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ label: query.value }),
            },
        );
        select(payload.data);
    } catch (error) {
        const validationError =
            error instanceof JsonRequestError &&
            typeof error.payload === 'object' &&
            error.payload !== null &&
            'errors' in error.payload
                ? (error.payload as { errors?: { label?: string[] } }).errors
                      ?.label?.[0]
                : undefined;
        errorMessage.value =
            validationError ?? requestErrorMessage(error, 'save');
    } finally {
        adding.value = false;
    }
};

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        open.value = false;

        return;
    }

    if (event.key === 'ArrowDown' && results.value.length) {
        event.preventDefault();
        open.value = true;
        activeIndex.value = (activeIndex.value + 1) % results.value.length;
    } else if (event.key === 'ArrowUp' && results.value.length) {
        event.preventDefault();
        open.value = true;
        activeIndex.value =
            (activeIndex.value - 1 + results.value.length) %
            results.value.length;
    } else if (event.key === 'Enter') {
        event.preventDefault();
        const option = results.value[activeIndex.value];

        if (open.value && option) {
            select(option);
        } else if (canAdd.value) {
            void add();
        }
    }
};
</script>

<template>
    <div class="relative grid gap-1 text-xs">
        <label :for="listboxId">{{ label }}</label>
        <div class="relative">
            <input
                :id="listboxId"
                v-model="query"
                type="text"
                role="combobox"
                :aria-expanded="open"
                :aria-controls="`${listboxId}-listbox`"
                aria-autocomplete="list"
                :disabled="disabled"
                autocomplete="off"
                class="h-9 w-full rounded-md border border-input bg-background px-3 pr-10 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                @focus="void search()"
                @blur="closeOptions"
                @keydown="onKeydown"
            />
            <button
                type="button"
                :aria-label="`Show saved ${label.toLowerCase()} options`"
                :disabled="disabled"
                class="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-muted-foreground hover:text-foreground disabled:cursor-not-allowed disabled:opacity-50"
                @mousedown.prevent
                @click="toggleOptions"
            >
                <ChevronDown class="size-4" aria-hidden="true" />
            </button>
        </div>
        <p class="text-[11px] text-muted-foreground">
            Choose a saved value or type a new one to add it to the list.
        </p>
        <ul
            v-if="open"
            :id="`${listboxId}-listbox`"
            role="listbox"
            class="absolute top-full z-50 mt-1 max-h-56 w-full overflow-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
        >
            <li
                v-for="(option, index) in results"
                :id="`${listboxId}-option-${index}`"
                :key="option.value"
                role="option"
                :aria-selected="index === activeIndex"
                class="cursor-pointer rounded-sm px-3 py-2 text-sm hover:bg-accent"
                :class="{ 'bg-accent': index === activeIndex }"
                @mouseenter="activeIndex = index"
                @mousedown.prevent="select(option)"
            >
                {{ option.label }}
            </li>
            <li v-if="loading" class="px-3 py-2 text-muted-foreground">
                Loading…
            </li>
            <li
                v-if="canAdd"
                class="cursor-pointer rounded-sm px-3 py-2 text-sm font-medium hover:bg-accent"
                @mousedown.prevent="void add()"
            >
                {{ adding ? 'Saving…' : `Add “${query.trim()}”` }}
            </li>
            <li
                v-if="!loading && results.length === 0 && !canAdd"
                class="px-3 py-2 text-muted-foreground"
            >
                No saved options.
            </li>
        </ul>
        <InputError :message="error || errorMessage" />
    </div>
</template>
