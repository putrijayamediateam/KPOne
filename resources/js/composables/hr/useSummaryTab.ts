import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SummaryTabData } from '@/components/hr/attendance/types';

/**
 * The attendance summary tab's state, moved out of `components/hr/attendance/SummaryTab.vue`.
 */
export function useSummaryTab(props: { data: SummaryTabData }) {
    const visit = (params: Record<string, string | number | undefined>) =>
        router.get(
            '/hr/attendance',
            {
                tab: 'summary',
                branch: props.data.branch?.id,
                period: props.data.period,
                date: props.data.date,
                ...params,
            },
            { preserveScroll: true },
        );
    const branchOptions = computed(() =>
        props.data.branches.map((b) => ({ value: b.id, label: b.name })),
    );
    const figureKeys = [
        'rostered',
        'worked',
        'late',
        'leftEarly',
        'noClockIn',
        'noClockOut',
        'overtime',
    ] as const;
    const periods = [
        { value: 'week', label: 'Week' },
        { value: 'month', label: 'Month' },
    ];

    return {
        visit,
        branchOptions,
        figureKeys,
        periods,
    };
}
