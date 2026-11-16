<script setup lang="ts">
// Version 2 onboarding and offboarding checklist (HR-PLAN 11.3 rule 11): the same tick request and key as Classic, from
// `useChecklistTab`. A period offers the onboarding list from its start and the offboarding list once it has an end
// date. A tick records who and when, and is final, so it is confirmed first with the item and period named. An item
// about the account only records that HR asked the account administrator; HR never changes an account.
import V2Chip from '@/components/v2/V2Chip.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import { useChecklistTab } from '@/composables/hr/useChecklistTab';
import type { ChecklistTabProps } from '@/composables/hr/useChecklistTab';
import { dateLabel } from './types';

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
const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9';
</script>

<template>
    <p
        v-if="errors.checklist && !pending"
        role="alert"
        class="text-sm text-destructive"
    >
        {{ errors.checklist }}
    </p>
    <div
        v-if="!periods.length"
        class="rounded-[14px] border bg-card p-6 text-center"
    >
        <p class="font-medium">No employment period yet.</p>
        <p class="text-sm text-muted-foreground">
            Add a period on the Employment tab; its onboarding list appears
            here.
        </p>
    </div>
    <section
        v-for="p in periods"
        :key="p.periodId"
        class="flex flex-col gap-3 rounded-[14px] border bg-card p-4"
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
                        <V2Chip
                            v-if="item.ticked"
                            tone="success"
                            :label="`${item.aboutAccount ? 'Asked' : 'Done'} · ${tickedLabel(item.ticked)}`"
                        />
                        <button
                            v-else-if="canManage"
                            type="button"
                            :class="button"
                            data-test="tick"
                            @click="ask(p, item)"
                        >
                            {{
                                item.aboutAccount
                                    ? 'Mark as asked'
                                    : 'Mark done'
                            }}
                        </button>
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
    <V2Dialog
        :open="pending !== null"
        :title="pending?.item.aboutAccount ? 'Mark as asked?' : 'Mark as done?'"
        :description="confirmText"
        :confirm-label="
            pending?.item.aboutAccount ? 'Mark as asked' : 'Mark done'
        "
        :busy="processing"
        @update:open="(open: boolean) => !open && (pending = null)"
        @confirm="confirmTick"
    >
        <p
            v-if="errors.checklist ?? errors.records"
            role="alert"
            class="text-sm text-destructive"
        >
            {{ errors.checklist ?? errors.records }}
        </p>
    </V2Dialog>
</template>
