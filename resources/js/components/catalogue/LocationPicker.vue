<script setup lang="ts">
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { OperationalSelect } from '@/components/ui/select';
import { JsonRequestError, requestJson } from '@/lib/json-client';

type Location = {
    publicId: string;
    name: string;
    branchId: number | null;
    type: string;
};
const props = defineProps<{
    modelValue: string;
    branchId: string;
    locations: Location[];
    canCreate: boolean;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const options = ref([...props.locations]);
const creating = ref(false);
const saving = ref(false);
const code = ref('');
const name = ref('');
const error = ref('');
const visibleLocations = computed(() =>
    options.value.filter(
        (location) =>
            location.branchId === null ||
            String(location.branchId) === props.branchId,
    ),
);
const selectOptions = computed(() =>
    visibleLocations.value.map((location) => ({
        value: location.publicId,
        label: `${location.name} · ${location.type}`,
    })),
);
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
const save = async () => {
    if (saving.value || !props.branchId) {
        return;
    }

    saving.value = true;
    error.value = '';

    try {
        const response = await requestJson<{ data: Location }>(
            '/catalogue-setup/locations',
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({
                    branch_id: Number(props.branchId),
                    code: code.value,
                    name: name.value,
                    type: 'medical_stock',
                }),
            },
        );
        options.value = [...options.value, response.data];
        emit('update:modelValue', response.data.publicId);
        code.value = '';
        name.value = '';
        creating.value = false;
    } catch (reason) {
        error.value =
            reason instanceof JsonRequestError
                ? 'Location could not be saved. Check its code and name.'
                : 'Location could not be saved.';
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <div class="grid gap-2">
        <OperationalSelect
            :model-value="modelValue"
            label="Stock location"
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
            Add location
        </Button>
        <form
            v-if="creating"
            class="grid gap-2 rounded-md bg-muted/40 p-3 sm:grid-cols-[1fr_1fr_auto]"
            @submit.prevent="save"
        >
            <Input
                v-model="code"
                placeholder="Location code"
                maxlength="64"
                required
            />
            <Input
                v-model="name"
                placeholder="Location name"
                maxlength="200"
                required
            />
            <Button type="submit" :disabled="saving">
                {{ saving ? 'Saving…' : 'Save location' }}
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
