<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { requestJson } from '@/lib/json-client';
import { myr } from '@/types/billing';
import type { OutstandingBillPage, OutstandingBills } from '@/types/billing';

const props = defineProps<{ groups: OutstandingBills | null }>();
const page = usePage();
const open = ref(false);
const contextBlocked = ref(false);
const blocked = computed(
    () =>
        contextBlocked.value ||
        props.groups?.branchId !== page.props.branchContext?.active?.id,
);
const selectedGroup = ref<'patient' | 'panel'>('patient');
const selectedUrl = ref('');
const result = ref<OutstandingBillPage | null>(null);
const loading = ref(false);
const error = ref('');
const titles = {
    patient: 'Patient outstanding',
    panel: 'Unclaimed Panel invoices',
};
const groups = computed(() =>
    (['patient', 'panel'] as const).flatMap((key) =>
        props.groups?.[key]
            ? [{ key, ...props.groups[key], title: titles[key] }]
            : [],
    ),
);
let controller: AbortController | null = null;
let generation = 0;
const cancel = () => {
    generation++;
    controller?.abort();
    loading.value = false;
    selectedUrl.value = '';
    result.value = null;
};
const load = async (number = 1) => {
    cancel();
    const group = props.groups?.[selectedGroup.value];

    if (!group || !open.value || blocked.value) {
        return;
    }

    const current = generation;
    controller = new AbortController();
    loading.value = true;
    error.value = '';

    try {
        const data = await requestJson<OutstandingBillPage>(
            `${group.url}?page=${number}`,
            { signal: controller.signal },
        );

        if (current === generation && open.value && !blocked.value) {
            result.value = data;
        }
    } catch {
        if (current === generation) {
            error.value =
                'Outstanding bills could not be loaded. Try again or refresh this page.';
        }
    } finally {
        if (current === generation) {
            loading.value = false;
        }
    }
};
const show = (group: 'patient' | 'panel') => {
    selectedGroup.value = group;
    open.value = true;
    void load();
};
const confirm = () => {
    if (
        loading.value ||
        blocked.value ||
        !result.value?.data.some((row) => row.url === selectedUrl.value)
    ) {
        return;
    }

    router.visit(selectedUrl.value);
};
watch(open, (value) => {
    if (!value) {
        cancel();
    }
});
watch(
    () => [props.groups, page.props.branchContext?.active?.id],
    () => {
        open.value = false;
        cancel();
        contextBlocked.value = false;
    },
);
const stopStart = router.on('start', (event) => {
    if (event.detail.visit.url.pathname === '/branch-context') {
        contextBlocked.value = true;
        open.value = false;
        cancel();
    }
});
onBeforeUnmount(() => {
    cancel();
    stopStart();
});
</script>

<template>
    <section
        v-if="groups.length && !blocked"
        class="rounded-xl border bg-card p-4 text-[13px]"
        data-testid="outstanding-bills"
    >
        <h2 class="font-medium">Outstanding bill</h2>
        <p class="mt-1 text-[11px] text-muted-foreground">
            Other invoices in this branch
        </p>
        <div
            v-for="group in groups"
            :key="group.key"
            class="mt-3 border-t pt-3"
            :data-testid="`outstanding-${group.key}`"
        >
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-xs font-medium">{{ group.title }}</h3>
                <Button
                    type="button"
                    variant="outline"
                    class="h-[30px] px-2 text-xs"
                    :aria-label="`View all ${group.title.toLowerCase()}`"
                    @click="show(group.key)"
                    >View all</Button
                >
            </div>
            <dl class="mt-2 grid grid-cols-2 divide-x">
                <div class="pr-3">
                    <dt class="text-[11px] text-muted-foreground">
                        Number of invoices
                    </dt>
                    <dd class="mt-1 text-base font-semibold tabular-nums">
                        {{ group.count }}
                    </dd>
                </div>
                <div class="pl-3">
                    <dt class="text-[11px] text-muted-foreground">
                        Total outstanding
                    </dt>
                    <dd class="mt-1 font-semibold tabular-nums">
                        {{ myr(group.totalSen) }}
                    </dd>
                </div>
            </dl>
        </div>
    </section>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-h-[90dvh] overflow-y-auto text-[13px] sm:max-w-lg"
            data-testid="outstanding-dialog"
        >
            <DialogHeader
                ><DialogTitle class="text-sm">{{
                    titles[selectedGroup]
                }}</DialogTitle
                ><DialogDescription class="text-xs"
                    >Which invoice would you like to open? Confirm opens Billing
                    without recording anything.</DialogDescription
                ></DialogHeader
            >
            <p v-if="loading" role="status">Loading outstanding bills…</p>
            <div v-else-if="error" role="alert" class="space-y-2">
                <p>{{ error }}</p>
                <Button
                    type="button"
                    variant="outline"
                    class="h-[30px] text-xs"
                    @click="load()"
                    >Try again</Button
                >
            </div>
            <template v-else-if="result">
                <p class="text-xs text-muted-foreground">
                    {{ result.count }} invoices · {{ myr(result.totalSen) }}
                </p>
                <fieldset class="grid max-h-[45dvh] gap-2 overflow-y-auto">
                    <legend class="sr-only">Select an invoice</legend>
                    <label
                        v-for="row in result.data"
                        :key="row.url"
                        class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 hover:bg-muted/40"
                    >
                        <input
                            v-model="selectedUrl"
                            type="radio"
                            name="outstanding-invoice"
                            :value="row.url"
                            class="shrink-0 accent-primary"
                        />
                        <span class="grid min-w-0 gap-1"
                            ><span class="font-semibold tabular-nums">{{
                                myr(row.amountSen)
                            }}</span
                            ><span class="text-xs text-muted-foreground">{{
                                row.visitDate
                            }}</span
                            ><span>{{ row.invoiceNumber }}</span
                            ><span
                                v-if="row.reviewRequired"
                                class="text-xs text-muted-foreground"
                                >Review required before settlement</span
                            ></span
                        >
                    </label>
                </fieldset>
                <p v-if="!result.data.length" class="text-muted-foreground">
                    No outstanding invoices in this group.
                </p>
                <div class="flex items-center justify-between gap-2 text-xs">
                    <Button
                        type="button"
                        variant="outline"
                        class="h-[30px] text-xs"
                        :disabled="result.currentPage <= 1"
                        @click="load(result.currentPage - 1)"
                        >Previous</Button
                    ><span
                        >Page {{ result.currentPage }} of
                        {{ result.lastPage }}</span
                    ><Button
                        type="button"
                        variant="outline"
                        class="h-[30px] text-xs"
                        :disabled="result.currentPage >= result.lastPage"
                        @click="load(result.currentPage + 1)"
                        >Next</Button
                    >
                </div>
            </template>
            <DialogFooter
                ><Button
                    type="button"
                    variant="outline"
                    class="h-[30px] text-xs"
                    @click="open = false"
                    >Cancel</Button
                ><Button
                    type="button"
                    class="h-[30px] text-xs"
                    :disabled="loading || !selectedUrl || !!error"
                    @click="confirm"
                    >Confirm</Button
                ></DialogFooter
            >
        </DialogContent>
    </Dialog>
</template>
