<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

export type VisitReasonOption = {
    publicId: string;
    name: string;
    isActive?: boolean;
};

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
const exactMatch = ref<VisitReasonOption | null>(null);
const open = ref(false);
const listboxId = `visit-reason-results-${useId()}`;
let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
const normalizedQuery = computed(() =>
    query.value.trim().replace(/\s+/g, ' ').toLocaleLowerCase(),
);
const normalizeLabel = (value: string) =>
    value.trim().replace(/\s+/g, ' ').toLocaleLowerCase();
const exactExists = computed(() => exactMatch.value !== null);
const inactiveExactMatch = computed(() =>
    exactMatch.value?.isActive === false ? exactMatch.value : null,
);
const activeOption = computed(() =>
    open.value && activeIndex.value >= 0
        ? results.value[activeIndex.value]
        : undefined,
);
const activeDescendant = computed(() =>
    activeOption.value ? `${listboxId}-option-${activeIndex.value}` : undefined,
);
const canAdd = computed(
    () =>
        normalizedQuery.value.length > 0 &&
        !exactExists.value &&
        props.selected.length < 5,
);

const search = async () => {
    controller?.abort();
    const searchTerm = query.value.trim();

    if (searchTerm === '') {
        results.value = [];
        exactMatch.value = null;
        activeIndex.value = -1;
        open.value = false;
        searching.value = false;

        return;
    }

    const current = new AbortController();
    controller = current;
    searching.value = true;
    requestError.value = '';

    try {
        const response = await fetch(
            `/visit-reasons?query=${encodeURIComponent(searchTerm)}`,
            { credentials: 'same-origin', signal: current.signal },
        );
        const payload = await response.json();

        if (!response.ok) {
            throw new Error();
        }

        const available = payload.data as VisitReasonOption[];
        exactMatch.value =
            available.find(
                (item) => normalizeLabel(item.name) === normalizedQuery.value,
            ) ?? null;
        results.value = available.filter(
            (item) =>
                item.isActive !== false &&
                !props.modelValue.includes(item.publicId),
        );
        open.value = query.value.trim().length > 0;
        activeIndex.value = open.value && results.value.length ? 0 : -1;
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
    controller?.abort();

    if (timer) {
        clearTimeout(timer);
    }

    open.value = false;
    activeIndex.value = -1;

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
    exactMatch.value = null;
    open.value = false;
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

        const created = payload.data as VisitReasonOption;

        if (created.isActive === false) {
            exactMatch.value = created;
            results.value = [];
            activeIndex.value = -1;
            open.value = true;

            return;
        }

        select(created);
    } catch {
        requestError.value = 'Visit Reason could not be added.';
    } finally {
        adding.value = false;
    }
};
const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Enter') {
        event.preventDefault();

        if (activeOption.value) {
            select(activeOption.value);
        }
    } else if (event.key === 'ArrowDown' && results.value.length) {
        event.preventDefault();
        open.value = true;
        activeIndex.value = (activeIndex.value + 1) % results.value.length;
    } else if (event.key === 'ArrowUp' && results.value.length) {
        event.preventDefault();
        open.value = true;
        activeIndex.value =
            (activeIndex.value - 1 + results.value.length) %
            results.value.length;
    } else if (event.key === 'Home' && results.value.length) {
        event.preventDefault();
        activeIndex.value = 0;
    } else if (event.key === 'End' && results.value.length) {
        event.preventDefault();
        activeIndex.value = results.value.length - 1;
    } else if (event.key === 'Escape') {
        event.preventDefault();

        if (timer) {
            clearTimeout(timer);
        }

        controller?.abort();
        searching.value = false;
        open.value = false;
        activeIndex.value = -1;
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
                :aria-controls="listboxId"
                :aria-expanded="open"
                aria-autocomplete="list"
                :aria-activedescendant="activeDescendant"
                :aria-invalid="!!error"
                :disabled="disabled || selected.length >= 5"
                maxlength="120"
                class="h-9 w-full rounded-md border border-input bg-card px-3 text-sm outline-none hover:border-foreground/25 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/40"
                :placeholder="
                    selected.length >= 5
                        ? 'Maximum 5 reasons selected'
                        : 'Search Visit Reasons'
                "
                @focus="search"
                @keydown="onKeydown"
            />
            <div
                v-if="
                    open &&
                    query.trim() &&
                    (results.length ||
                        canAdd ||
                        searching ||
                        inactiveExactMatch)
                "
                class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border bg-popover p-1 shadow-md"
            >
                <div :id="listboxId" role="listbox">
                    <button
                        v-for="(item, index) in results"
                        :key="item.publicId"
                        :id="`${listboxId}-option-${index}`"
                        type="button"
                        role="option"
                        :aria-selected="index === activeIndex"
                        class="block w-full rounded px-3 py-2 text-left text-sm hover:bg-muted"
                        :class="index === activeIndex ? 'bg-muted' : ''"
                        @click="select(item)"
                        @mouseenter="activeIndex = index"
                    >
                        {{ item.name }}
                    </button>
                </div>
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
                    v-if="inactiveExactMatch"
                    class="px-3 py-2 text-xs text-amber-800 dark:text-amber-300"
                >
                    This Visit Reason is inactive. Choose another or ask a
                    Supervisor.
                </p>
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
        <p class="sr-only" role="status" aria-live="polite">
            {{
                searching
                    ? 'Searching Visit Reasons.'
                    : `${results.length} Visit Reason results available.`
            }}
        </p>
        <InputError :message="error || requestError" />
    </div>
</template>
