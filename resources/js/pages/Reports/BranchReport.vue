<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import MultiSelectFilterChip from '@/components/reports/MultiSelectFilterChip.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/presentation';
type Filters = {
    expected_branch_id: number;
    from: string;
    to: string;
    page: number;
    methods: string[];
    visit_types: string[];
};
type Result = {
    generatedAt: string;
    count: number;
    page: number;
    pages: number;
    columns: string[];
    rows: string[][];
    totals: Record<string, string>;
};
type Payload = {
    title: string;
    basis: string;
    endpoint: string;
    branch: { id: number; name: string; timezone: string };
    canExport: boolean;
    filters: Filters;
    options: Record<
        'methods' | 'visit_types',
        { value: string; label: string }[]
    >;
    result: Result | null;
};
const props = defineProps<Payload>();
const page = usePage();
const clone = <T,>(value: T): T => JSON.parse(JSON.stringify(value));
const draft = ref(clone(props.filters));
const applied = ref(clone(props.filters));
const data = ref<Result | null>(null);
const pending = ref<Record<string, boolean>>({});
const error = ref('');
const busy = ref(false);
const alive = ref(true);
let request: AbortController | null = null;
let generation = 0;
const dirty = computed(
    () =>
        JSON.stringify({ ...draft.value, page: 1 }) !==
            JSON.stringify({ ...applied.value, page: 1 }) ||
        Object.values(pending.value).some(Boolean),
);
const headers = () => ({
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-CSRF-TOKEN':
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? '',
});
function clear() {
    generation++;
    request?.abort();
    data.value = null;
    error.value = '';
    busy.value = false;
    pending.value = {};
    draft.value = clone(props.filters);
    applied.value = clone(props.filters);
}
watch(() => props.branch.id, clear);
watch(() => page.props.branchContext?.active?.id, clear);
onBeforeUnmount(() => {
    alive.value = false;
    clear();
});
async function perform(download = false, targetPage = 1) {
    if (
        busy.value ||
        (download && (dirty.value || !data.value || !props.canExport))
    ) {
        return;
    }

    busy.value = true;
    error.value = '';
    request = new AbortController();
    const branchId = props.branch.id;
    const ownGeneration = generation;
    const filters = {
        ...clone(download || targetPage !== 1 ? applied.value : draft.value),
        page: targetPage,
    };

    try {
        const response = await fetch(
            `${props.endpoint}/${download ? 'download' : 'run'}`,
            {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: headers(),
                body: JSON.stringify(filters),
                signal: request.signal,
            },
        );

        if (
            !alive.value ||
            branchId !== props.branch.id ||
            ownGeneration !== generation
        ) {
            return;
        }

        if (!response.ok) {
            const payload = await response.json().catch(() => null);

            throw new Error(
                payload?.errors
                    ? Object.values(payload.errors).flat().join(' ')
                    : response.status === 429
                      ? 'Too many requests. Try again shortly.'
                      : response.status === 403
                        ? 'Report access is no longer available. Reload the page.'
                        : 'Unable to prepare the report. Try again.',
            );
        }

        if (download) {
            const blob = await response.blob();

            if (
                !alive.value ||
                branchId !== props.branch.id ||
                ownGeneration !== generation
            ) {
                return;
            }

            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `${props.endpoint.split('/').pop()}_${filters.from}_${filters.to}.csv`;
            link.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        } else {
            const payload: Payload = await response.json();

            if (
                !alive.value ||
                branchId !== props.branch.id ||
                ownGeneration !== generation
            ) {
                return;
            }

            data.value = payload.result;
            applied.value = clone(payload.filters);
            draft.value = clone(payload.filters);
        }
    } catch (reason) {
        if (
            reason instanceof Error &&
            reason.name !== 'AbortError' &&
            alive.value &&
            branchId === props.branch.id &&
            ownGeneration === generation
        ) {
            error.value = reason.message;

            if (!download) {
                data.value = null;
            }
        }
    } finally {
        if (
            alive.value &&
            branchId === props.branch.id &&
            ownGeneration === generation
        ) {
            busy.value = false;
        }
    }
}
function applySelection(key: 'methods' | 'visit_types', value: string[]) {
    draft.value[key] = value;

    if (data.value) {
        void perform();
    }
}
</script>
<template>
    <Head :title="title" />
    <main
        class="mx-auto flex w-full max-w-7xl min-w-0 flex-1 flex-col gap-5 p-4 md:p-6"
    >
        <header class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ title }}</h1>
                <p class="text-sm text-muted-foreground">
                    {{ branch.name }} · {{ branch.timezone }}
                </p>
            </div>
            <Button
                v-if="canExport"
                :disabled="busy || !data || dirty"
                @click="perform(true)"
                >Download CSV</Button
            >
        </header>
        <p class="text-sm text-muted-foreground">{{ basis }}</p>
        <section
            aria-label="Report filters"
            class="space-y-3 rounded-lg border bg-card p-4"
        >
            <div class="flex flex-wrap items-end gap-3">
                <label class="grid gap-1 text-sm"
                    >From<Input v-model="draft.from" type="date"
                /></label>
                <label class="grid gap-1 text-sm"
                    >To<Input v-model="draft.to" type="date"
                /></label>
                <Button :disabled="busy" @click="perform()">Run report</Button>
            </div>
            <p class="text-xs text-muted-foreground">
                Maximum 31 local dates. Downloads use applied filters and
                current records at their own generation time.
            </p>
            <div class="flex flex-wrap gap-2">
                <MultiSelectFilterChip
                    label="Payment method"
                    :options="options.methods"
                    :model-value="draft.methods"
                    @update:model-value="applySelection('methods', $event)"
                    @pending="pending.methods = $event"
                />
                <MultiSelectFilterChip
                    label="Visit type"
                    :options="options.visit_types"
                    :model-value="draft.visit_types"
                    @update:model-value="applySelection('visit_types', $event)"
                    @pending="pending.visit_types = $event"
                />
            </div>
        </section>
        <p v-if="dirty" class="text-sm text-muted-foreground">
            Filters have unapplied changes. Run report before downloading.
        </p>
        <p
            v-if="error"
            role="alert"
            class="rounded-lg border border-destructive p-3 text-sm text-destructive"
        >
            {{ error }}
        </p>
        <template v-if="data">
            <p class="text-sm">
                {{ data.count }} rows · Generated
                <time :datetime="data.generatedAt">{{
                    formatDateTime(data.generatedAt, branch.timezone)
                }}</time>
                ({{ branch.timezone }}). A later download can differ.
            </p>
            <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="(value, label) in data.totals"
                    :key="label"
                    class="rounded-lg border bg-card p-3 text-sm"
                >
                    <dt class="text-muted-foreground">{{ label }}</dt>
                    <dd class="font-semibold tabular-nums">{{ value }}</dd>
                </div>
            </dl>
            <div
                class="max-w-full overflow-x-auto rounded-lg border"
                role="region"
                aria-label="Report table; scroll horizontally"
                tabindex="0"
            >
                <table class="w-full min-w-[1200px] text-left text-sm">
                    <thead class="bg-muted">
                        <tr>
                            <th
                                v-for="column in data.columns"
                                :key="column"
                                class="p-3"
                            >
                                {{ column }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, i) in data.rows"
                            :key="i"
                            class="border-t"
                        >
                            <td
                                v-for="(cell, j) in row"
                                :key="j"
                                class="p-3 tabular-nums"
                            >
                                {{ cell }}
                            </td>
                        </tr>
                        <tr v-if="!data.rows.length">
                            <td
                                :colspan="data.columns.length"
                                class="p-6 text-muted-foreground"
                            >
                                No matching rows.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav
                class="flex flex-wrap items-center justify-between gap-3"
                aria-label="Report pages"
            >
                <Button
                    variant="outline"
                    :disabled="busy || dirty || data.page <= 1"
                    @click="perform(false, data.page - 1)"
                    >Previous</Button
                ><span>Page {{ data.page }} of {{ data.pages }}</span
                ><Button
                    variant="outline"
                    :disabled="busy || dirty || data.page >= data.pages"
                    @click="perform(false, data.page + 1)"
                    >Next</Button
                >
            </nav>
        </template>
        <p
            v-else-if="!busy && !error"
            class="rounded-lg border border-dashed p-6 text-sm text-muted-foreground"
        >
            Choose a period and run the report. Rows are not loaded
            automatically.
        </p>
    </main>
</template>
