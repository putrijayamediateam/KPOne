import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import type { Option } from '@/components/hr/payroll/types';

/**
 * HR-7d yearly MTD schedule sheet state: year, rounding, minimum, reliefs and bands, and the request, for the Payroll
 * MTD schedule sheet.
 */
export type MtdScheduleSheetProps = {
    open: boolean;
    rounding: Option[];
    reliefs: Option[];
    maxBands: number;
    year: number;
};

export type Band = {
    from: string;
    to: string;
    rate: string;
    base_cat13: string;
    base_cat2: string;
};

export function useMtdScheduleSheet(
    props: MtdScheduleSheetProps,
    close: () => void,
) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const failure = computed(
        () => errors.value.payroll ?? errors.value.records,
    );
    const band = (from = ''): Band => ({
        from,
        to: '',
        rate: '',
        base_cat13: '',
        base_cat2: '',
    });
    const emptyReliefs = () =>
        Object.fromEntries(props.reliefs.map((r) => [r.value, ''])) as Record<
            string,
            string
        >;
    const form = reactive({
        year: '',
        rounding: '',
        minimum: '',
        reliefs: {} as Record<string, string>,
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
                    year: String(props.year),
                    rounding: '',
                    minimum: '',
                    reliefs: emptyReliefs(),
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
            '/hr/payroll/mtd-schedules',
            {
                year: form.year,
                rounding: form.rounding,
                minimum: form.minimum,
                ...form.reliefs,
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
        band,
        emptyReliefs,
        form,
        addBand,
        removeBand,
        send,
        rowError,
    };
}
