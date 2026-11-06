<script setup lang="ts">
// HR-4b onboarding and offboarding (HR-PLAN 11.3 rule 11). A period offers the onboarding list from its start and the
// offboarding list once it has an end date. A tick records who and when, and is final. An item about the account shows
// "Ask the account administrator": ticking it records only that HR asked; HR never changes an account.
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import type { ChecklistEntry, ChecklistPeriod } from './types';
import { dateLabel } from './types';

const props = defineProps<{
    periods: ChecklistPeriod[];
    workerId: string;
    canManage: boolean;
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
// A tick is final, so it is confirmed with the item and the period named (Suresh, HR-4b S3).
const pending = ref<{ period: ChecklistPeriod; item: ChecklistEntry } | null>(
    null,
);
const processing = ref(false);
const key = ref('');
const ask = (period: ChecklistPeriod, item: ChecklistEntry) => {
    pending.value = { period, item };
    key.value = crypto.randomUUID();
};
const confirmTick = () => {
    const p = pending.value;

    if (!p) {
        return;
    }

    processing.value = true;
    router.post(
        `/hr/records/${props.workerId}/periods/${p.period.periodId}/checklist/${p.item.id}`,
        { idempotency_key: key.value },
        {
            preserveScroll: true,
            onSuccess: () => (pending.value = null),
            onFinish: () => (processing.value = false),
        },
    );
};
const confirmText = computed(() => {
    const p = pending.value;

    if (!p) {
        return '';
    }

    const period = `${p.period.typeLabel} from ${dateLabel(p.period.startsOn)}`;

    return p.item.aboutAccount
        ? `"${p.item.label}" for ${period}. This records that you asked the account administrator. It cannot be undone.`
        : `"${p.item.label}" for ${period}. This records that it is done. It cannot be undone.`;
});
const done = (items: ChecklistEntry[]) => items.filter((i) => i.ticked).length;
const tickedLabel = (t: { by: string; at: string }) =>
    `${t.by}, ${new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(t.at))}`;
</script>

<template>
    <ErrorState
        v-if="errors.checklist && !pending"
        :message="errors.checklist"
    />
    <EmptyState
        v-if="!periods.length"
        title="No employment period yet."
        description="Add a period on the Employment tab; its onboarding list appears here."
    />
    <section
        v-for="p in periods"
        :key="p.periodId"
        class="flex flex-col gap-3 rounded-xl border bg-card p-4"
        data-test="checklist-period"
    >
        <h3 class="text-sm font-semibold">
            {{ p.typeLabel }} from {{ dateLabel(p.startsOn) }}
            <template v-if="p.endsOn"> to {{ dateLabel(p.endsOn) }}</template>
        </h3>
        <template
            v-for="list in [
                { key: 'onboarding', title: 'Onboarding', items: p.onboarding },
                ...(p.offboarding
                    ? [
                          {
                              key: 'offboarding',
                              title: 'Offboarding',
                              items: p.offboarding,
                          },
                      ]
                    : []),
            ]"
            :key="list.key"
        >
            <div class="flex flex-col gap-2">
                <p class="text-sm font-medium">
                    {{ list.title }}
                    <span class="font-normal text-muted-foreground"
                        >· {{ done(list.items) }} of
                        {{ list.items.length }} done</span
                    >
                </p>
                <p
                    v-if="!list.items.length"
                    class="text-sm text-muted-foreground"
                >
                    No {{ list.title.toLowerCase() }} items yet. Add them under
                    Staff files, Documents and checklist settings.
                </p>
                <ul v-else class="flex flex-col divide-y">
                    <li
                        v-for="item in list.items"
                        :key="item.id"
                        class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
                        data-test="checklist-item"
                    >
                        <div class="min-w-0">
                            <p>{{ item.label }}</p>
                            <p
                                v-if="item.aboutAccount"
                                class="text-xs text-muted-foreground"
                            >
                                Ask the account administrator. HR does not
                                change accounts.
                            </p>
                        </div>
                        <StatusBadge
                            v-if="item.ticked"
                            status="done"
                            :label="`${item.aboutAccount ? 'Asked' : 'Done'} · ${tickedLabel(item.ticked)}`"
                            tone="success"
                        />
                        <Button
                            v-else-if="canManage"
                            variant="outline"
                            size="sm"
                            class="max-lg:min-h-11"
                            data-test="tick"
                            @click="ask(p, item)"
                            >{{
                                item.aboutAccount
                                    ? 'Mark as asked'
                                    : 'Mark done'
                            }}</Button
                        >
                        <span v-else class="text-muted-foreground"
                            >Not done</span
                        >
                    </li>
                </ul>
            </div>
        </template>
        <p v-if="!p.endsOn" class="text-xs text-muted-foreground">
            The offboarding list appears once this period has an end date.
        </p>
    </section>
    <OperationalConfirmDialog
        :open="pending !== null"
        :title="pending?.item.aboutAccount ? 'Mark as asked?' : 'Mark as done?'"
        :description="confirmText"
        :confirm-label="
            pending?.item.aboutAccount ? 'Mark as asked' : 'Mark done'
        "
        processing-label="Saving…"
        :processing="processing"
        :error="errors.checklist ?? errors.records"
        @update:open="(v: boolean) => !v && (pending = null)"
        @confirm="confirmTick"
    />
</template>
