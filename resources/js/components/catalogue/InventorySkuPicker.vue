<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { JsonRequestError, requestJson } from '@/lib/json-client';

type Option = { value: string; label: string };
const props = withDefaults(
    defineProps<{
        modelValue: string;
        error?: string;
    }>(),
    { error: '' },
);
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const query = ref('');
const results = ref<Option[]>([]);
const selectedLabel = ref('');
const open = ref(false);
const loading = ref(false);
const errorMessage = ref('');
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

const search = async () => {
    controller?.abort();
    const current = new AbortController();
    controller = current;
    loading.value = true;
    errorMessage.value = '';

    try {
        const params = new URLSearchParams();

        if (query.value.trim()) {
            params.set('query', query.value.trim());
        }

        const response = await requestJson<{ data: Option[] }>(
            `/medicines/inventory-skus?${params.toString()}`,
            { signal: current.signal },
        );
        results.value = response.data;
        open.value = true;
    } catch (reason) {
        if (reason instanceof DOMException && reason.name === 'AbortError') {
            return;
        }

        errorMessage.value =
            reason instanceof JsonRequestError
                ? 'Inventory SKUs could not be loaded.'
                : 'Inventory SKUs could not be loaded.';
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

    timer = setTimeout(() => void search(), 150);
});
watch(
    () => props.modelValue,
    (value) => {
        if (!value) {
            selectedLabel.value = '';
        }
    },
);
onBeforeUnmount(() => {
    if (timer) {
        clearTimeout(timer);
    }

    controller?.abort();
});

const select = (option: Option) => {
    emit('update:modelValue', option.value);
    selectedLabel.value = option.label;
    query.value = option.label;
    open.value = false;
};
const createNew = () => {
    emit('update:modelValue', '');
    selectedLabel.value = '';
    query.value = '';
    open.value = false;
};
</script>

<template>
    <div class="relative grid gap-1 text-xs">
        <label for="medicine-inventory-sku">Existing inventory SKU</label>
        <input
            id="medicine-inventory-sku"
            v-model="query"
            type="text"
            role="combobox"
            :aria-expanded="open"
            aria-autocomplete="list"
            autocomplete="off"
            class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
            :placeholder="selectedLabel || 'Search by SKU code or item name'"
            @focus="void search()"
            @keydown.escape="open = false"
        />
        <ul
            v-if="open"
            role="listbox"
            class="absolute top-full z-50 mt-1 max-h-56 w-full overflow-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md"
        >
            <li
                class="cursor-pointer rounded-sm px-3 py-2 text-sm font-medium hover:bg-accent"
                @mousedown.prevent="createNew"
            >
                Create a linked SKU
            </li>
            <li
                v-for="option in results"
                :key="option.value"
                role="option"
                class="cursor-pointer rounded-sm px-3 py-2 text-sm hover:bg-accent"
                @mousedown.prevent="select(option)"
            >
                {{ option.label }}
            </li>
            <li v-if="loading" class="px-3 py-2 text-muted-foreground">
                Loading…
            </li>
        </ul>
        <p v-if="selectedLabel && !open" class="text-muted-foreground">
            Selected: {{ selectedLabel }}
        </p>
        <InputError :message="error || errorMessage" />
    </div>
</template>
