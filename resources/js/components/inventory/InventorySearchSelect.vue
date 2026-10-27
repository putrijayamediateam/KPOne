<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { OperationalSelect } from '@/components/ui/select';
import type { OperationalSelectOption } from '@/components/ui/select/OperationalSelect.vue';
import {
    inventoryPickerUrl,
    inventoryPickerOptions,
    mergeInventoryPickerOptions,
} from '@/lib/inventory-picker';
import type { InventoryPickerRow } from '@/lib/inventory-picker';
import { requestJson } from '@/lib/json-client';

const props = withDefaults(
    defineProps<{
        modelValue: string | number;
        options: OperationalSelectOption[];
        label: string;
        kind: string;
        expectedBranchId: number;
        context?: string;
        sku?: string;
        id?: string;
        disabled?: boolean;
    }>(),
    { context: 'references', sku: '', disabled: false },
);
const emit = defineEmits<{ 'update:modelValue': [value: string | number] }>();
const query = ref('');
const matches = ref<OperationalSelectOption[] | null>(null);
const selected = ref<OperationalSelectOption>();
const loading = ref(false);
const error = ref('');
let sequence = 0;
const choices = computed(() => {
    const rows = matches.value ?? props.options;
    const empty = props.options.find((option) => option.value === '');
    const pinned =
        selected.value?.value === props.modelValue ? selected.value : undefined;

    return mergeInventoryPickerOptions(rows, empty, pinned);
});
watch(
    () => props.modelValue,
    (value) => {
        selected.value = choices.value.find(
            (option) => String(option.value) === String(value),
        );
    },
);
const search = async () => {
    const request = ++sequence;
    loading.value = true;
    error.value = '';
    const url = inventoryPickerUrl({
        kind: props.kind,
        context: props.context,
        expectedBranchId: props.expectedBranchId,
        query: query.value,
        sku: props.sku,
    });

    try {
        const response = await requestJson<{
            data: InventoryPickerRow[];
        }>(url);

        if (request !== sequence) {
            return;
        }

        matches.value = inventoryPickerOptions(response.data, props.kind);
    } catch {
        if (request === sequence) {
            error.value = 'Choices could not be loaded. Try again.';
        }
    } finally {
        if (request === sequence) {
            loading.value = false;
        }
    }
};
watch(
    () => props.sku,
    () => {
        sequence++;
        matches.value = null;
        selected.value = undefined;
        query.value = '';
        loading.value = false;

        if (props.kind === 'batches' && props.sku) {
            void search();
        }
    },
);
watch(
    () => props.expectedBranchId,
    () => {
        sequence++;
        query.value = '';
        matches.value = null;
        selected.value = undefined;
        error.value = '';
        loading.value = false;
    },
);
</script>

<template>
    <div class="min-w-0 space-y-1">
        <div class="flex min-w-0 gap-1">
            <Input
                v-model="query"
                :aria-label="`Search ${label}`"
                :placeholder="`Search ${label}`"
                maxlength="100"
                class="min-w-0"
                :disabled="disabled"
                @keydown.enter.prevent="search"
            />
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="disabled || loading"
                @click="search"
                >Search</Button
            >
        </div>
        <p class="text-xs text-muted-foreground">
            Up to 25 matches. Search to find more.
        </p>
        <OperationalSelect
            :id="id"
            :model-value="modelValue"
            :label="label"
            :options="choices"
            :disabled="disabled || loading"
            :content-avoid-collisions="false"
            @update:model-value="emit('update:modelValue', $event)"
        />
        <p v-if="error" role="alert" class="text-xs text-destructive">
            {{ error }}
        </p>
    </div>
</template>
