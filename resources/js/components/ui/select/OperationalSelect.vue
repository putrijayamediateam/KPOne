<script setup lang="ts">
import { computed } from 'vue';
import Select from './Select.vue';
import SelectContent from './SelectContent.vue';
import SelectItem from './SelectItem.vue';
import SelectTrigger from './SelectTrigger.vue';
import SelectValue from './SelectValue.vue';

export type OperationalSelectOption = {
    value: string | number;
    label: string;
    disabled?: boolean;
};

const emptyValue = '__kpone_empty_selection__';
const props = withDefaults(
    defineProps<{
        modelValue: string | number;
        options: OperationalSelectOption[];
        label: string;
        id?: string;
        labelledby?: string;
        placeholder?: string;
        disabled?: boolean;
        invalid?: boolean;
        triggerClass?: string;
    }>(),
    { placeholder: 'Select', disabled: false, invalid: false, triggerClass: '' },
);
const emit = defineEmits<{
    'update:modelValue': [value: string | number];
}>();

const encodedValue = computed(() =>
    props.modelValue === '' ? emptyValue : String(props.modelValue),
);
const encodedOptions = computed(() =>
    props.options.map((option) => ({
        ...option,
        encoded: option.value === '' ? emptyValue : String(option.value),
    })),
);
const update = (value: unknown) => {
    const option = encodedOptions.value.find((item) => item.encoded === String(value ?? ''));
    emit('update:modelValue', option?.value ?? '');
};
</script>

<template>
    <Select
        :model-value="encodedValue"
        :disabled="disabled"
        @update:model-value="update"
    >
        <SelectTrigger
            :id="id"
            class="w-full"
            :class="triggerClass"
            :aria-label="labelledby ? undefined : label"
            :aria-labelledby="labelledby"
            :aria-invalid="invalid || undefined"
        >
            <SelectValue :placeholder="placeholder" />
        </SelectTrigger>
        <SelectContent>
            <SelectItem
                v-for="option in encodedOptions"
                :key="option.encoded"
                :value="option.encoded"
                :disabled="option.disabled"
            >
                {{ option.label }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
