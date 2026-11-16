<script setup lang="ts">
// HR-4b onboarding and offboarding (HR-PLAN 11.3 rule 11). A period offers the onboarding list from its start and the
// offboarding list once it has an end date. A tick records who and when, and is final. An item about the account shows
// "Ask the account administrator": ticking it records only that HR asked; HR never changes an account.
import { dateLabel } from '@/components/hr/records/types';
import { Button } from '@/components/ui/button';
import OperationalConfirmDialog from '@/components/ui/OperationalConfirmDialog.vue';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { useChecklistTab } from '@/composables/hr/useChecklistTab';
import type { ChecklistTabProps } from '@/composables/hr/useChecklistTab';

const props = defineProps<ChecklistTabProps>();

const {
    errors,
    pending,
    processing,
    ask,
    confirmTick,
    confirmText,
    done,
    tickedLabel,
} = useChecklistTab(props);
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
