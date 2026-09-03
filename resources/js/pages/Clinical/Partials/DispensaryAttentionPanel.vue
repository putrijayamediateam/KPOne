<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AlertTriangle, Check, LoaderCircle } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import type { DoctorDispensaryAttention } from '@/types';

defineProps<{ items: DoctorDispensaryAttention[] }>();

const processing = ref<string | null>(null);
const actionError = ref<string | null>(null);

const acknowledge = (item: DoctorDispensaryAttention) => {
    processing.value = item.exceptionPublicId;
    actionError.value = null;
    router.post(
        item.acknowledgeUrl,
        {
            case_lock_version: item.caseLockVersion,
            item_lock_version: item.itemLockVersion,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                actionError.value =
                    Object.values(errors)[0] ??
                    'This Dispensary proposal changed. Refresh and review it again.';
            },
            onFinish: () => (processing.value = null),
        },
    );
};
</script>

<template>
    <section
        v-if="items.length"
        aria-labelledby="dispensary-attention-heading"
        class="divide-y rounded-lg border border-amber-200 bg-amber-50/40"
    >
        <header class="flex items-center gap-2 px-3 py-2">
            <AlertTriangle class="size-4 shrink-0 text-amber-700" />
            <h2 id="dispensary-attention-heading" class="text-sm font-semibold">
                Dispensary attention required
            </h2>
        </header>
        <article
            v-for="item in items"
            :key="item.exceptionPublicId"
            class="grid gap-2 px-3 py-2.5 text-sm sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"
        >
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <p class="font-medium">
                        {{ item.medicineName }}
                        <span
                            v-if="item.strength"
                            class="font-normal text-muted-foreground"
                        >
                            {{ item.strength }}
                        </span>
                    </p>
                    <span
                        v-if="item.status === 'acknowledged'"
                        class="inline-flex items-center gap-1 text-xs text-emerald-700"
                    >
                        <Check class="size-3.5" /> Acknowledged
                    </span>
                    <span v-else class="text-xs font-medium text-amber-800">
                        {{
                            item.reviewAgain
                                ? 'Updated by Dispensary — review again'
                                : 'Dispensary review required'
                        }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    Ordered: {{ item.quantityOrdered }} {{ item.unit }} ·
                    Patient will take: {{ item.proposedQuantity }}
                    {{ item.unit }} · {{ item.reason }}
                </p>
            </div>
            <Button
                v-if="item.status === 'awaiting_acknowledgement'"
                size="sm"
                :disabled="processing === item.exceptionPublicId"
                @click="acknowledge(item)"
            >
                <LoaderCircle
                    v-if="processing === item.exceptionPublicId"
                    class="size-4 animate-spin"
                />
                Acknowledge partial fulfilment
            </Button>
        </article>
        <p
            v-if="actionError"
            role="alert"
            class="px-3 py-2 text-xs text-red-700"
        >
            {{ actionError }}
        </p>
    </section>
</template>
