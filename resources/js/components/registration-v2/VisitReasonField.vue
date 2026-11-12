<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import V2ChipChoice from '@/components/v2/V2ChipChoice.vue';
import V2SearchCombobox from '@/components/v2/V2SearchCombobox.vue';
import type { VisitReasonOption } from '@/components/visit/VisitReasonPicker.vue';
import { JsonRequestError, requestJson } from '@/lib/json-client';

/**
 * Version 2 visit reason field: the same behaviour as the Classic `components/visit/VisitReasonPicker.vue` (untouched):
 * search `GET /visit-reasons?query=`, add a new reason with `POST /visit-reasons` (`{ name }`, CSRF header) when the
 * typed text is not an existing one, at most 5, the first is primary, an inactive exact match is refused with the
 * same message. Only the look changes: a V2SearchCombobox to search and add, and V2ChipChoice for the chosen ones.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string[];
        selected: VisitReasonOption[];
        error?: string;
        disabled?: boolean;
    }>(),
    { error: '', disabled: false },
);
const emit = defineEmits<{
    'update:modelValue': [value: string[]];
    'update:selected': [value: VisitReasonOption[]];
}>();

const ADD_ID = '__add__';
const query = ref('');
const results = ref<VisitReasonOption[]>([]);
const searching = ref(false);
const adding = ref(false);
const requestError = ref('');
const exactMatch = ref<VisitReasonOption | null>(null);
const combo = ref<InstanceType<typeof V2SearchCombobox> | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
const normalize = (value: string) =>
    value.trim().replace(/\s+/g, ' ').toLocaleLowerCase();
const canAdd = computed(
    () =>
        normalize(query.value).length > 0 &&
        exactMatch.value === null &&
        props.selected.length < 5,
);
const inactiveExact = computed(() =>
    exactMatch.value?.isActive === false ? exactMatch.value : null,
);
const options = computed(() => [
    ...results.value.map((item) => ({ id: item.publicId, label: item.name })),
    ...(canAdd.value
        ? [
              {
                  id: ADD_ID,
                  label: `Add "${query.value.trim().replace(/\s+/g, ' ')}"`,
              },
          ]
        : []),
]);

const search = async () => {
    controller?.abort();
    const term = query.value.trim();

    if (term === '') {
        results.value = [];
        exactMatch.value = null;
        searching.value = false;

        return;
    }

    const current = new AbortController();
    controller = current;
    searching.value = true;
    requestError.value = '';

    try {
        const payload = await requestJson<{ data: VisitReasonOption[] }>(
            `/visit-reasons?${new URLSearchParams({ query: term }).toString()}`,
            { signal: current.signal },
        );
        exactMatch.value =
            payload.data.find(
                (item) => normalize(item.name) === normalize(query.value),
            ) ?? null;
        results.value = payload.data.filter(
            (item) =>
                item.isActive !== false &&
                !props.modelValue.includes(item.publicId),
        );
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            return;
        }

        requestError.value = 'Visit reasons could not be loaded.';
    } finally {
        if (controller === current) {
            searching.value = false;
        }
    }
};
watch(query, () => {
    controller?.abort();

    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(search, 150);
});
onBeforeUnmount(() => {
    if (timer) {
        clearTimeout(timer);
    }

    controller?.abort();
});

const setSelected = (selected: VisitReasonOption[]) => {
    emit(
        'update:modelValue',
        selected.map((reason) => reason.publicId),
    );
    emit('update:selected', selected);
};
const choose = (item: VisitReasonOption) => {
    if (
        props.selected.length >= 5 ||
        props.modelValue.includes(item.publicId)
    ) {
        return;
    }

    setSelected([...props.selected, item]);
    query.value = '';
    results.value = [];
    exactMatch.value = null;
};
const add = async () => {
    if (!canAdd.value || adding.value) {
        return;
    }

    adding.value = true;
    requestError.value = '';

    try {
        const payload = await requestJson<{ data: VisitReasonOption }>(
            '/visit-reasons',
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ name: query.value }),
            },
        );

        if (payload.data.isActive === false) {
            exactMatch.value = payload.data;
            results.value = [];

            return;
        }

        choose(payload.data);
    } catch (error) {
        const validationError =
            error instanceof JsonRequestError &&
            typeof error.payload === 'object' &&
            error.payload !== null &&
            'errors' in error.payload
                ? (error.payload as { errors?: { name?: string[] } }).errors
                      ?.name?.[0]
                : undefined;
        requestError.value =
            validationError ?? 'Visit reason could not be added.';
    } finally {
        adding.value = false;
    }
};
const onSelect = (option: { id: string }) => {
    if (option.id === ADD_ID) {
        void add();

        return;
    }

    const item = results.value.find((reason) => reason.publicId === option.id);

    if (item) {
        choose(item);
    }
};
const chipOptions = computed(() =>
    props.selected.map((reason) => ({
        value: reason.publicId,
        label: reason.name,
    })),
);
// V2ChipChoice also emits a new primary right after a removal; the list order already says who is first.
let removing = false;
const onRemove = (ids: string[]) => {
    removing = true;
    void nextTick(() => {
        removing = false;
    });
    setSelected(
        ids
            .map((id) => props.selected.find((r) => r.publicId === id))
            .filter((r): r is VisitReasonOption => !!r),
    );
};
const onPrimary = (id: string | null) => {
    if (removing) {
        return;
    }

    const item = props.selected.find((reason) => reason.publicId === id);

    if (!item || props.selected[0]?.publicId === id) {
        return;
    }

    setSelected([
        item,
        ...props.selected.filter((reason) => reason.publicId !== id),
    ]);
};
const errorText = computed(() => props.error || requestError.value);
defineExpose({ focus: () => combo.value?.focus() });
</script>

<template>
    <div class="flex min-w-0 flex-col gap-3">
        <V2SearchCombobox
            ref="combo"
            v-model="query"
            label="Visit reason"
            placeholder="Search visit reasons"
            :options="options"
            :loading="searching"
            empty-text="No matching visit reason."
            :class="disabled || selected.length >= 5 ? 'opacity-60' : ''"
            @select="onSelect"
        />
        <p
            v-if="inactiveExact"
            class="rounded-[10px] border border-warning/40 bg-warning/10 px-3 py-2 text-sm"
        >
            This visit reason is inactive. Choose another or ask a Supervisor.
        </p>
        <V2ChipChoice
            v-if="selected.length"
            label="Chosen visit reasons"
            :options="chipOptions"
            :model-value="modelValue"
            :primary="selected[0]?.publicId ?? null"
            :max="5"
            noun="reasons"
            @update:model-value="onRemove"
            @update:primary="onPrimary"
        />
        <p v-else class="text-[13px] text-muted-foreground">
            Up to 5 reasons. 0 chosen. The first one is primary.
        </p>
        <p v-if="errorText" role="alert" class="text-sm text-destructive">
            {{ errorText }}
        </p>
    </div>
</template>
