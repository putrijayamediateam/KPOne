<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { OperationalSelect } from '@/components/ui/select';
import { JsonRequestError, requestJson } from '@/lib/json-client';

type Supplier = { publicId: string; code: string; name: string };
const props = defineProps<{
    modelValue: string;
    suppliers: Supplier[];
    canCreate: boolean;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const options = ref([...props.suppliers]);
const creating = ref(false);
const saving = ref(false);
const code = ref('');
const name = ref('');
const error = ref('');
watch(
    () => props.suppliers,
    (value) => {
        options.value = [...value];
    },
);
const selectOptions = computed(() => [
    { value: '', label: 'No supplier recorded' },
    ...options.value.map((supplier) => ({
        value: supplier.publicId,
        label: `${supplier.name} (${supplier.code})`,
    })),
]);
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
const save = async () => {
    if (saving.value) {
        return;
    }

    saving.value = true;
    error.value = '';

    try {
        const response = await requestJson<{ data: Supplier }>(
            '/catalogue-setup/suppliers',
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ code: code.value, name: name.value }),
            },
        );
        options.value = [...options.value, response.data].sort((left, right) =>
            left.name.localeCompare(right.name),
        );
        emit('update:modelValue', response.data.publicId);
        code.value = '';
        name.value = '';
        creating.value = false;
    } catch (reason) {
        error.value =
            reason instanceof JsonRequestError
                ? 'Supplier could not be saved. Check its code and name.'
                : 'Supplier could not be saved.';
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <div class="grid gap-2">
        <OperationalSelect
            :model-value="modelValue"
            label="Supplier"
            :options="selectOptions"
            @update:model-value="
                (value) => emit('update:modelValue', String(value))
            "
        />
        <Button
            v-if="canCreate && !creating"
            type="button"
            size="sm"
            variant="outline"
            class="w-fit"
            @click="creating = true"
        >
            Add supplier
        </Button>
        <form
            v-if="creating"
            class="grid gap-2 rounded-md bg-muted/40 p-3 sm:grid-cols-[1fr_1fr_auto]"
            @submit.prevent="save"
        >
            <Input
                v-model="code"
                placeholder="Supplier code"
                maxlength="64"
                required
            />
            <Input
                v-model="name"
                placeholder="Supplier name"
                maxlength="200"
                required
            />
            <Button type="submit" :disabled="saving">
                {{ saving ? 'Saving…' : 'Save supplier' }}
            </Button>
            <Button type="button" variant="ghost" @click="creating = false">
                Cancel
            </Button>
            <p v-if="error" class="text-sm text-destructive sm:col-span-3">
                {{ error }}
            </p>
        </form>
    </div>
</template>
