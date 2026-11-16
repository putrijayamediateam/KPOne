<script setup lang="ts">
/**
 * Version 2 look for the doctor's Dispensary attention panel on the V2 consultation page (owner: "start the next
 * Dispensary V2 page", 2026-11-16; Daniel's OK). Classic stays `pages/Clinical/Partials/DispensaryAttentionPanel.vue`,
 * untouched. Same props, the same POST (acknowledgeUrl with the case and item lock versions) and the same refusal
 * fallback; tests/Frontend/v2-dispensary-attention-panel.test.mjs keeps the request in step with Classic.
 */
import { router } from '@inertiajs/vue3';
import { AlertTriangle, LoaderCircle } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import V2Chip from '@/components/v2/V2Chip.vue';
import { quantityWords } from '@/pages/Dispensary/V2/Partials/quantity-words';
import type { DoctorDispensaryAttention } from '@/types';

const props = defineProps<{ items: DoctorDispensaryAttention[] }>();

const processing = ref<string | null>(null);
// V2 (Daniel): the refusal shows under the row it came from, not at the bottom of the panel.
const errorFor = ref<{ id: string; message: string } | null>(null);
const status = ref('');
const showAll = ref(false);
const visibleLimit = 5;

// V2 (Daniel, Hakim): Classic's order with the rows that still need the doctor first, fixed for the life of the
// panel, so a row the doctor acts on stays where it is; rows that arrive later go to the end.
const waitingFirst = (items: DoctorDispensaryAttention[]) => [
    ...items.filter((i) => i.status === 'awaiting_acknowledgement'),
    ...items.filter((i) => i.status !== 'awaiting_acknowledgement'),
];
const order = ref<string[]>(
    waitingFirst(props.items).map((i) => i.exceptionPublicId),
);
watch(
    () => props.items.map((i) => i.exceptionPublicId),
    () => {
        const known = new Set(order.value);
        order.value = [
            ...order.value,
            ...waitingFirst(props.items)
                .map((i) => i.exceptionPublicId)
                .filter((id) => !known.has(id)),
        ];
    },
);
const ordered = computed(() => {
    const byId = new Map(props.items.map((i) => [i.exceptionPublicId, i]));

    return order.value
        .map((id) => byId.get(id))
        .filter((i): i is DoctorDispensaryAttention => i !== undefined);
});
// Rows the doctor acted on stay in view when the list is collapsed.
const pinned = ref(new Set<string>());
// Mira: collapsed, never hide a row that still needs review.
const shown = computed(() =>
    showAll.value
        ? ordered.value
        : ordered.value.filter(
              (item, index) =>
                  index < visibleLimit ||
                  item.status === 'awaiting_acknowledgement' ||
                  pinned.value.has(item.exceptionPublicId),
          ),
);
const hiddenCount = computed(() => ordered.value.length - shown.value.length);
const canCollapse = computed(
    () =>
        showAll.value &&
        ordered.value.filter(
            (item, index) =>
                index < visibleLimit ||
                item.status === 'awaiting_acknowledgement' ||
                pinned.value.has(item.exceptionPublicId),
        ).length < ordered.value.length,
);

const medicine = (item: DoctorDispensaryAttention) =>
    item.strength ? `${item.medicineName} ${item.strength}` : item.medicineName;
const rowId = (item: DoctorDispensaryAttention) =>
    `dispensary-attention-${item.exceptionPublicId}`;
const errorId = (item: DoctorDispensaryAttention) => `${rowId(item)}-error`;

const focusRow = (item: DoctorDispensaryAttention, button: boolean) => {
    pinned.value = new Set([...pinned.value, item.exceptionPublicId]);

    // Safety net: if the row is not shown, open the full list before moving focus.
    if (
        !shown.value.some((i) => i.exceptionPublicId === item.exceptionPublicId)
    ) {
        showAll.value = true;
    }

    return nextTick(() =>
        setTimeout(() => {
            const row = document.getElementById(rowId(item));
            const target = button
                ? row?.querySelector<HTMLElement>('button[data-acknowledge]')
                : row;
            (target ?? row)?.focus({ preventScroll: true });
        }, 0),
    );
};

const acknowledge = (item: DoctorDispensaryAttention) => {
    processing.value = item.exceptionPublicId;
    errorFor.value = null;
    status.value = '';
    router.post(
        item.acknowledgeUrl,
        {
            case_lock_version: item.caseLockVersion,
            item_lock_version: item.itemLockVersion,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                status.value = `Acknowledged partial fulfilment for ${medicine(item)}.`;
                focusRow(item, false);
            },
            onError: (errors) => {
                errorFor.value = {
                    id: item.exceptionPublicId,
                    message:
                        Object.values(errors)[0] ??
                        'This Dispensary proposal changed. Refresh and review it again.',
                };
                focusRow(item, true);
            },
            onFinish: () => (processing.value = null),
        },
    );
};

const outlineButton =
    'inline-flex h-11 items-center justify-center gap-2 rounded-[10px] border border-border bg-card px-4 text-sm font-medium outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-60 max-sm:w-full lg:h-9 dark:border-foreground/20';
</script>

<template>
    <section
        v-if="items.length"
        aria-labelledby="dispensary-attention-heading"
        class="overflow-hidden rounded-[14px] border border-l-4 border-l-warning bg-card dark:border-foreground/20 dark:border-l-warning"
        data-testid="v2-dispensary-attention"
    >
        <header class="flex items-center gap-2 px-4 pt-3 pb-1">
            <AlertTriangle
                class="size-4 shrink-0 text-warning-strong"
                aria-hidden="true"
            />
            <h2
                id="dispensary-attention-heading"
                class="text-[15px] font-semibold"
            >
                Dispensary attention required
            </h2>
        </header>
        <ul
            id="dispensary-attention-rows"
            class="divide-y dark:divide-foreground/15"
        >
            <li
                v-for="item in shown"
                :id="rowId(item)"
                :key="item.exceptionPublicId"
                tabindex="-1"
                class="grid gap-2 px-4 py-3 outline-none focus-visible:ring-2 focus-visible:ring-ring sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
                :class="
                    item.status === 'acknowledged'
                        ? 'text-muted-foreground'
                        : ''
                "
                data-testid="v2-dispensary-attention-row"
            >
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <p
                            class="text-[15px] font-medium break-words"
                            :class="
                                item.status === 'acknowledged'
                                    ? 'text-muted-foreground'
                                    : 'text-foreground'
                            "
                        >
                            {{ item.medicineName }}
                            <span
                                v-if="item.strength"
                                class="font-normal text-muted-foreground"
                                >{{ item.strength }}</span
                            >
                        </p>
                        <V2Chip
                            v-if="item.status === 'acknowledged'"
                            tone="success"
                            label="Acknowledged"
                        />
                        <V2Chip
                            v-else-if="item.reviewAgain"
                            tone="warning"
                            label="Updated by Dispensary — review again"
                        />
                        <V2Chip
                            v-else
                            tone="warning"
                            label="Dispensary review required"
                        />
                    </div>
                    <p class="mt-1 text-[13px] text-muted-foreground">
                        Ordered:
                        {{ quantityWords(item.quantityOrdered, item.unit) }} ·
                        Patient will take:
                        {{ quantityWords(item.proposedQuantity, item.unit) }} ·
                        {{ item.reason }}
                    </p>
                    <p
                        v-if="errorFor?.id === item.exceptionPublicId"
                        :id="errorId(item)"
                        role="alert"
                        class="mt-2 text-[13px] text-destructive"
                    >
                        {{ errorFor.message }}
                    </p>
                </div>
                <button
                    v-if="item.status === 'awaiting_acknowledgement'"
                    type="button"
                    data-acknowledge
                    :class="outlineButton"
                    :disabled="processing === item.exceptionPublicId"
                    :aria-busy="processing === item.exceptionPublicId"
                    :aria-describedby="
                        errorFor?.id === item.exceptionPublicId
                            ? errorId(item)
                            : undefined
                    "
                    :aria-label="`Acknowledge partial fulfilment for ${medicine(item)}`"
                    @click="acknowledge(item)"
                >
                    <LoaderCircle
                        v-if="processing === item.exceptionPublicId"
                        class="size-4 animate-spin"
                        aria-hidden="true"
                    />
                    Acknowledge partial fulfilment
                </button>
            </li>
        </ul>
        <div
            v-if="hiddenCount > 0 || canCollapse"
            class="border-t px-4 py-2 dark:border-foreground/15"
        >
            <button
                type="button"
                :class="[outlineButton, 'lg:h-11!']"
                :aria-expanded="showAll"
                aria-controls="dispensary-attention-rows"
                data-testid="v2-dispensary-attention-show-all"
                @click="showAll = !showAll"
            >
                {{ showAll ? 'Show fewer' : `Show all ${ordered.length}` }}
            </button>
        </div>
        <p class="sr-only" role="status" aria-live="polite">{{ status }}</p>
    </section>
</template>
