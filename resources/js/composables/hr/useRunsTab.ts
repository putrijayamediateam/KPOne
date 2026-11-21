import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import type { Option, RunRow } from '@/components/hr/payroll/types';

/**
 * HR-7b Runs tab state: Prepare a run for a pay group and month. The request lives here, shared by the Classic tab
 * (`components/hr/payroll/RunsTab.vue`) and V2 Payroll, so both send the same request. Moved unchanged.
 */
export type RunsTabProps = {
    runs: RunRow[];
    months: Option[];
    groups: Option[];
    ready: boolean;
    prepareRefusal: string | null;
};

export function useRunsTab(props: RunsTabProps) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const form = reactive({
        group: props.groups.length === 1 ? props.groups[0].value : '',
        month: props.months[2]?.value ?? '',
        kind: 'regular',
        key: crypto.randomUUID(),
        processing: false,
    });
    const kinds: Option[] = [
        { value: 'regular', label: 'Monthly run' },
        { value: 'adjustment', label: 'Adjustment to a locked run' },
    ];
    const prepare = () => {
        form.processing = true;
        router.post(
            '/hr/payroll/runs',
            {
                pay_group_id: form.group,
                month: form.month,
                kind: form.kind,
                idempotency_key: form.key,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    form.processing = false;
                    form.key = crypto.randomUUID();
                },
            },
        );
    };

    return {
        errors,
        form,
        kinds,
        prepare,
    };
}
