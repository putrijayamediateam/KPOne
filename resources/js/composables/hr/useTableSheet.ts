import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import type { Option } from '@/components/hr/payroll/types';

/**
 * HR-7a statutory table sheet state: scheme, category, date, rounding, source and wage bands, and the request, for the
 * Payroll statutory table sheet.
 */
export type TableSheetProps = {
    open: boolean;
    schemes: Option[];
    rounding: Option[];
    maxRows: number;
};

export type Band = {
    from: string;
    to: string;
    employee_kind: string;
    employee: string;
    employer_kind: string;
    employer: string;
};

export function useTableSheet(props: TableSheetProps, close: () => void) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const failure = computed(
        () => errors.value.payroll ?? errors.value.records,
    );
    const kinds: Option[] = [
        { value: 'rate', label: '% of wages' },
        { value: 'fixed', label: 'Fixed RM' },
    ];
    const band = (from = ''): Band => ({
        from,
        to: '',
        employee_kind: 'rate',
        employee: '',
        employer_kind: 'rate',
        employer: '',
    });
    const form = reactive({
        scheme: '',
        category: '',
        effectiveFrom: '',
        rounding: '',
        sourceNote: '',
        rows: [band('0.00')] as Band[],
        key: '',
        processing: false,
    });
    watch(
        () => props.open,
        (open) => {
            if (open) {
                Object.assign(form, {
                    scheme: '',
                    category: '',
                    effectiveFrom: '',
                    rounding: '',
                    sourceNote: '',
                    rows: [band('0.00')],
                    key: crypto.randomUUID(),
                });
            }
        },
    );
    /** The next band starts one sen after the last one ends, so bands run on without a gap. */
    const addBand = () => {
        const last = form.rows[form.rows.length - 1];
        const match = last?.to
            .replace(/,/g, '')
            .match(/^(\d+)(?:\.(\d{1,2}))?$/);
        let next = '';

        if (match) {
            const sen =
                Number(match[1]) * 100 +
                Number((match[2] ?? '').padEnd(2, '0')) +
                1;
            next = `${Math.trunc(sen / 100)}.${String(sen % 100).padStart(2, '0')}`;
        }

        form.rows.push(band(next));
    };
    const removeBand = (index: number) => {
        form.rows = form.rows.filter((_, i) => i !== index);
    };
    const send = () => {
        form.processing = true;
        router.post(
            '/hr/payroll/tables',
            {
                scheme: form.scheme,
                category: form.category,
                effective_from: form.effectiveFrom,
                rounding: form.rounding,
                source_note: form.sourceNote,
                rows: form.rows,
                idempotency_key: form.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => close(),
                onFinish: () => (form.processing = false),
            },
        );
    };
    const rowError = (i: number, field: string) =>
        errors.value[`rows.${i}.${field}`];

    return {
        page,
        errors,
        failure,
        kinds,
        band,
        form,
        addBand,
        removeBand,
        send,
        rowError,
    };
}
