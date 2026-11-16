import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type {
    ChecklistEntry,
    ChecklistPeriod,
} from '@/components/hr/records/types';
import { dateLabel } from '@/components/hr/records/types';

/**
 * HR-4b onboarding and offboarding tick (HR-PLAN 11.3 rule 11): the same request and key for the Classic tab
 * (`components/hr/records/ChecklistTab.vue`) and Version 2. A tick is final. Moved out unchanged.
 */
export type ChecklistTabProps = {
    periods: ChecklistPeriod[];
    workerId: string;
    canManage: boolean;
};

export function useChecklistTab(props: ChecklistTabProps) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    // A tick is final, so it is confirmed with the item and the period named (Suresh, HR-4b S3).
    const pending = ref<{
        period: ChecklistPeriod;
        item: ChecklistEntry;
    } | null>(null);
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
    const done = (items: ChecklistEntry[]) =>
        items.filter((i) => i.ticked).length;
    const tickedLabel = (t: { by: string; at: string }) =>
        `${t.by}, ${new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(t.at))}`;

    return {
        errors,
        pending,
        processing,
        ask,
        confirmTick,
        confirmText,
        done,
        tickedLabel,
    };
}
