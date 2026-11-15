<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { JsonRequestError, requestJson } from '@/lib/json-client';

type Preset = { id: number; name: string };
const props = defineProps<{
    visitNumber: string;
    branchId: number;
    disabled: boolean;
}>();
const emit = defineEmits<{ select: [name: string] }>();
const open = ref(false);
const query = ref('');
const results = ref<Preset[]>([]);
const loading = ref(false);
const error = ref('');
let controller: AbortController | null = null;
let generation = 0;
let timer: ReturnType<typeof setTimeout> | undefined;
const cancel = () => {
    generation++;
    controller?.abort();
    clearTimeout(timer);
    loading.value = false;
    results.value = [];
};
const load = async () => {
    cancel();

    if (!open.value || props.disabled) {
        return;
    }

    const current = generation;
    controller = new AbortController();
    loading.value = true;
    error.value = '';

    try {
        const params = new URLSearchParams({
            expected_branch_id: String(props.branchId),
            q: query.value,
        });
        const response = await requestJson<{ data: Preset[] }>(
            `/visits/${encodeURIComponent(props.visitNumber)}/encounter/diagnosis-presets?${params}`,
            { signal: controller.signal },
        );

        if (current === generation && open.value && !props.disabled) {
            results.value = response.data;
        }
    } catch (failure) {
        if (current !== generation) {
            return;
        }

        error.value =
            failure instanceof JsonRequestError &&
            [403, 404, 422].includes(failure.status)
                ? 'This consultation is no longer available for preset selection. Refresh the consultation before continuing.'
                : 'Preset diagnoses could not be loaded. Try again.';
    } finally {
        if (current === generation) {
            loading.value = false;
        }
    }
};
watch(open, (value) => {
    cancel();
    error.value = '';

    if (value) {
        query.value = '';
        void load();
    }
});
watch(query, () => {
    cancel();

    if (open.value) {
        timer = setTimeout(() => {
            void load();
        }, 400);
    }
});
watch(
    () => [props.visitNumber, props.branchId, props.disabled],
    () => {
        open.value = false;
        cancel();
    },
);
onBeforeUnmount(cancel);
const choose = (preset: Preset) => {
    if (props.disabled || loading.value) {
        return;
    }

    emit('select', preset.name);
    open.value = false;
};
</script>
<template>
    <Button
        type="button"
        size="sm"
        variant="outline"
        :disabled="disabled"
        @click="open = true"
        >Choose preset</Button
    >
    <Dialog v-model:open="open"
        ><DialogContent class="sm:max-w-lg"
            ><DialogHeader
                ><DialogTitle>Choose preset diagnosis</DialogTitle
                ><DialogDescription
                    >Adds an editable diagnosis row. Use Save clinical record to
                    save it.</DialogDescription
                ></DialogHeader
            >
            <form class="flex gap-2" @submit.prevent="load">
                <label class="sr-only" for="diagnosis-preset-search"
                    >Search preset diagnoses</label
                ><input
                    id="diagnosis-preset-search"
                    v-model="query"
                    type="search"
                    maxlength="200"
                    placeholder="Search preset diagnoses"
                    class="h-10 min-w-0 flex-1 rounded-md border bg-background px-3 text-sm"
                /><Button type="submit" variant="outline">Search</Button>
            </form>
            <p
                v-if="loading"
                role="status"
                class="text-sm text-muted-foreground"
            >
                Loading presets…
            </p>
            <div
                v-else-if="error"
                role="alert"
                class="grid gap-2 text-sm text-destructive"
            >
                <p>{{ error }}</p>
                <Button
                    type="button"
                    variant="outline"
                    class="w-fit"
                    @click="load"
                    >Try again</Button
                >
            </div>
            <ul v-else-if="results.length" class="max-h-[50vh] overflow-y-auto">
                <li v-for="preset in results" :key="preset.id">
                    <button
                        type="button"
                        class="w-full rounded-md px-3 py-3 text-left text-sm [overflow-wrap:anywhere] break-words hover:bg-muted focus-visible:outline-ring"
                        @click="choose(preset)"
                    >
                        {{ preset.name }}
                    </button>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">
                No active presets found. You can still use Add diagnosis and
                enter free text.
            </p>
            <p class="text-xs text-muted-foreground">
                Showing up to 50 active presets. Search to narrow the list.
            </p>
        </DialogContent></Dialog
    >
</template>
