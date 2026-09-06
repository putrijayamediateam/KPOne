<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

export type VisitReasonOption = { publicId: string; name: string };

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

const query = ref('');
const results = ref<VisitReasonOption[]>([]);
const searching = ref(false);
const adding = ref(false);
const requestError = ref('');
const activeIndex = ref(-1);
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
const normalizedQuery = computed(() =>
    query.value.trim().replace(/\s+/g, ' ').toLocaleLowerCase(),
);
const exactExists = computed(() =>
    results.value.some(
        (item) => item.name.toLocaleLowerCase() === normalizedQuery.value,
    ),
);
const canAdd = computed(
    () =>
        normalizedQuery.value.length > 0 &&
        !exactExists.value &&
        props.selected.length < 5,
);

const search = async () => {
    controller?.abort();
    const current = new AbortController();
    controller = current;
    searching.value = true;
    requestError.value = '';

    try {
        const response = await fetch(
            `/visit-reasons?query=${encodeURIComponent(query.value.trim())}`,
            { credentials: 'same-origin', signal: current.signal },
        );
        const payload = await response.json();

        if (!response.ok) {
            throw new Error();
        }

        results.value = (payload.data as VisitReasonOption[]).filter(
            (item) => !props.modelValue.includes(item.publicId),
        );
        activeIndex.value = results.value.length ? 0 : -1;
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            return;
        }

        requestError.value = 'Visit Reasons could not be loaded.';
    } finally {
        if (controller === current) {
            searching.value = false;
        }
    }
};
watch(query, () => {
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
const select = (item: VisitReasonOption) => {
    if (
        props.selected.length >= 5 ||
        props.modelValue.includes(item.publicId)
    ) {
        return;
    }

    const selected = [...props.selected, item];
    emit(
        'update:modelValue',
        selected.map((reason) => reason.publicId),
    );
    emit('update:selected', selected);
    query.value = '';
    results.value = [];
};
const remove = (publicId: string) => {
    const selected = props.selected.filter(
        (reason) => reason.publicId !== publicId,
    );
    emit(
        'update:modelValue',
        selected.map((reason) => reason.publicId),
    );
    emit('update:selected', selected);
};
const makePrimary = (publicId: string) => {
    const item = props.selected.find((reason) => reason.publicId === publicId);

    if (!item) {
        return;
    }

    const selected = [
        item,
        ...props.selected.filter((reason) => reason.publicId !== publicId),
    ];
    emit(
        'update:modelValue',
        selected.map((reason) => reason.publicId),
    );
    emit('update:selected', selected);
};
const add = async () => {
    if (!canAdd.value || adding.value) {
        return;
    }

    adding.value = true;
    requestError.value = '';

    try {
        const response = await fetch('/visit-reasons', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ name: query.value }),
        });
        const payload = await response.json();

        if (!response.ok) {
            requestError.value =
                payload.errors?.name?.[0] ?? 'Visit Reason could not be added.';

            return;
        }

        select(payload.data as VisitReasonOption);
    } catch {
        requestError.value = 'Visit Reason could not be added.';
    } finally {
        adding.value = false;
    }
};
const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'ArrowDown' && results.value.length) {
        event.preventDefault();
        activeIndex.value = Math.min(
            activeIndex.value + 1,
            results.value.length - 1,
        );
    } else if (event.key === 'ArrowUp' && results.value.length) {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    } else if (event.key === 'Enter' && activeIndex.value >= 0) {
        event.preventDefault();
        select(results.value[activeIndex.value]);
    }
};
</script>

<template>
    <div class="grid gap-2">
        <div v-if="selected.length" class="grid gap-2">
            <div
                v-for="(reason, index) in selected"
                :key="reason.publicId"
                class="flex min-h-9 items-center gap-2 rounded-md border bg-background px-3 py-1.5 text-sm"
            >
                <span
                    v-if="index === 0"
                    class="rounded bg-foreground px-1.5 py-0.5 text-[10px] font-semibold text-background uppercase"
                    >Primary</span
                >
                <span v-else class="text-xs text-muted-foreground"
                    >Additional</span
                >
                <span class="min-w-0 flex-1 truncate">{{ reason.name }}</span>
                <button
                    v-if="index > 0"
                    type="button"
                    class="text-xs font-medium underline-offset-2 hover:underline"
                    @click="makePrimary(reason.publicId)"
                >
                    Make Primary
                </button>
                <button
                    type="button"
                    class="text-xs text-muted-foreground hover:text-foreground"
                    :aria-label="`Remove ${reason.name}`"
                    @click="remove(reason.publicId)"
                >
                    Remove
                </button>
            </div>
        </div>
        <div class="relative">
            <input
                v-model="query"
                type="search"
                autocomplete="off"
                role="combobox"
                aria-label="Search Visit Reasons"
                aria-controls="visit-reason-results"
                :aria-expanded="results.length > 0"
                :aria-invalid="!!error"
                :disabled="disabled || selected.length >= 5"
                class="h-10 w-full rounded-md border bg-background px-3 text-sm"
                :placeholder="
                    selected.length >= 5
                        ? 'Maximum 5 reasons selected'
                        : 'Search Visit Reasons'
                "
                @focus="search"
                @keydown="onKeydown"
            />
            <div
                v-if="query.trim() && (results.length || canAdd || searching)"
                id="visit-reason-results"
                role="listbox"
                class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border bg-popover p-1 shadow-md"
            >
                <button
                    v-for="(item, index) in results"
                    :key="item.publicId"
                    type="button"
                    role="option"
                    :aria-selected="index === activeIndex"
                    class="block w-full rounded px-3 py-2 text-left text-sm hover:bg-muted"
                    :class="index === activeIndex ? 'bg-muted' : ''"
                    @click="select(item)"
                >
                    {{ item.name }}
                </button>
                <Button
                    v-if="canAdd"
                    type="button"
                    variant="ghost"
                    class="w-full justify-start"
                    :disabled="adding"
                    @click="add"
                >
                    Add &quot;{{ query.trim().replace(/\s+/g, ' ') }}&quot;
                </Button>
                <p
                    v-if="searching"
                    class="px-3 py-2 text-xs text-muted-foreground"
                >
                    Searching…
                </p>
            </div>
        </div>
        <p class="text-xs text-muted-foreground">
            Select up to 5. The first reason is Primary.
        </p>
        <InputError :message="error || requestError" />
    </div>
</template>
