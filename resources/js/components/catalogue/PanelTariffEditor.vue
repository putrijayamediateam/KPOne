<script setup lang="ts">
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { OperationalSelect } from '@/components/ui/select';
import { JsonRequestError, requestJson } from '@/lib/json-client';

type PanelOption = { id: number; name: string };
type PanelTariff = { panel_id: string; amount_rm: string };

const props = defineProps<{
    modelValue: PanelTariff[];
    panels: PanelOption[];
}>();
const emit = defineEmits<{
    'update:modelValue': [value: PanelTariff[]];
}>();
const availablePanels = ref([...props.panels]);
const creatingPanel = ref(false);
const panelCode = ref('');
const panelName = ref('');
const creating = ref(false);
const error = ref('');

watch(
    () => props.panels,
    (panels) => {
        availablePanels.value = [...panels];
    },
);

const update = (index: number, key: keyof PanelTariff, value: string) => {
    const tariffs = props.modelValue.map((tariff) => ({ ...tariff }));
    tariffs[index][key] = value;
    emit('update:modelValue', tariffs);
};
const addTariff = () => {
    const selected = new Set(props.modelValue.map((tariff) => tariff.panel_id));
    const panel = availablePanels.value.find(
        (option) => !selected.has(String(option.id)),
    );

    if (panel) {
        emit('update:modelValue', [
            ...props.modelValue,
            { panel_id: String(panel.id), amount_rm: '' },
        ]);
    }
};
const removeTariff = (index: number) => {
    emit(
        'update:modelValue',
        props.modelValue.filter((_, itemIndex) => itemIndex !== index),
    );
};
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
const savePanel = async () => {
    if (creating.value) {
        return;
    }

    creating.value = true;
    error.value = '';

    try {
        const response = await requestJson<{
            data: PanelOption & { code: string };
        }>('/catalogue-panels', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({
                code: panelCode.value,
                name: panelName.value,
            }),
        });
        availablePanels.value = [...availablePanels.value, response.data].sort(
            (left, right) => left.name.localeCompare(right.name),
        );
        emit('update:modelValue', [
            ...props.modelValue,
            { panel_id: String(response.data.id), amount_rm: '' },
        ]);
        panelCode.value = '';
        panelName.value = '';
        creatingPanel.value = false;
    } catch (reason) {
        error.value =
            reason instanceof JsonRequestError
                ? 'The Panel could not be created. Check its code and name.'
                : 'The Panel could not be created.';
    } finally {
        creating.value = false;
    }
};
</script>

<template>
    <div class="grid gap-3">
        <div
            v-for="(tariff, index) in modelValue"
            :key="index"
            class="grid gap-2 md:grid-cols-[1fr_1fr_auto]"
        >
            <OperationalSelect
                :model-value="tariff.panel_id"
                label="Panel"
                :options="
                    availablePanels.map((panel) => ({
                        value: String(panel.id),
                        label: panel.name,
                    }))
                "
                @update:model-value="
                    (value) => update(index, 'panel_id', String(value))
                "
            />
            <label class="grid gap-1 text-xs">
                Panel tariff (RM)
                <Input
                    :model-value="tariff.amount_rm"
                    type="number"
                    min="0"
                    step="0.01"
                    @update:model-value="
                        (value) => update(index, 'amount_rm', String(value))
                    "
                />
            </label>
            <Button
                type="button"
                variant="outline"
                @click="removeTariff(index)"
            >
                Remove
            </Button>
        </div>
        <div class="flex flex-wrap gap-2">
            <Button
                v-if="availablePanels.length > modelValue.length"
                type="button"
                variant="outline"
                size="sm"
                @click="addTariff"
            >
                Add panel tariff
            </Button>
            <Button
                type="button"
                variant="outline"
                size="sm"
                @click="creatingPanel = !creatingPanel"
            >
                {{ creatingPanel ? 'Cancel new Panel' : 'Add new Panel' }}
            </Button>
        </div>
        <form
            v-if="creatingPanel"
            class="grid gap-2 rounded-md bg-muted/40 p-3 sm:grid-cols-[1fr_1fr_auto]"
            @submit.prevent="savePanel"
        >
            <Input
                v-model="panelCode"
                placeholder="Panel code"
                maxlength="80"
                required
            />
            <Input
                v-model="panelName"
                placeholder="Panel name"
                maxlength="255"
                required
            />
            <Button type="submit" :disabled="creating">
                {{ creating ? 'Saving…' : 'Save Panel' }}
            </Button>
            <p v-if="error" class="text-sm text-destructive sm:col-span-3">
                {{ error }}
            </p>
        </form>
    </div>
</template>
