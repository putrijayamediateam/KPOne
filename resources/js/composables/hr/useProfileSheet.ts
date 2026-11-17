import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import type { Option, Person } from '@/components/hr/payroll/types';
import { rmText } from '@/components/hr/payroll/types';

/**
 * HR-7a pay profile sheet state: the form, its defaults from the person's current profile, and the request. Shared by the Classic sheet (`components/hr/payroll/ProfileSheet.vue`) and V2 Payroll, so both send the same request. Moved unchanged.
 */
export type ProfileSheetProps = {
    open: boolean;
    person: Person | null;
    groups: Option[];
    allowanceTypes: Option[];
    categories: { epf: string[]; socso: string[] };
    maxItems: number;
    today: string;
    mtdCategories: Option[];
};

const schemes = [
    { key: 'epf', label: 'EPF' },
    { key: 'socso', label: 'SOCSO' },
    { key: 'eis', label: 'EIS' },
    { key: 'mtd', label: 'MTD (PCB)' },
    { key: 'hrd', label: 'HRD levy' },
] as const;
export type SchemeKey = (typeof schemes)[number]['key'];

const form = reactive({
    payGroup: '',
    payType: 'monthly',
    rate: '',
    effectiveFrom: '',
    schemes: {
        epf: false,
        socso: false,
        eis: false,
        mtd: false,
        hrd: false,
    } as Record<SchemeKey, boolean>,
    epfCategory: '',
    socsoCategory: '',
    // HR-7d (HR-PLAN 18.3): optional MTD details; with a category KPOne works MTD out on monthly runs.
    mtdCategory: 'none',
    mtdChildren: '0',
    mtdDisabledSelf: false,
    mtdDisabledSpouse: false,
    items: [] as { type: string; amount: string }[],
    key: '',
    processing: false,
});

export function useProfileSheet(props: ProfileSheetProps, close: () => void) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const failure = computed(
        () => errors.value.payroll ?? errors.value.records,
    );
    watch(
        () => props.open,
        (open) => {
            if (!open) {
                return;
            }

            const current = props.person?.current ?? null;
            const pick = (label: string) =>
                current?.schemes.find(
                    (s) => s === label || s.startsWith(`${label} (`),
                );
            const category = (label: string) =>
                pick(label)?.match(/\((.*)\)$/)?.[1] ?? '';
            Object.assign(form, {
                payGroup:
                    props.groups.find((g) => g.label === current?.payGroup)
                        ?.value ??
                    (props.groups.length === 1 ? props.groups[0].value : ''),
                payType: current?.payType ?? 'monthly',
                rate: rmText(current?.rateSen ?? null),
                effectiveFrom: '',
                schemes: {
                    epf: !!pick('EPF'),
                    socso: !!pick('SOCSO'),
                    eis: !!pick('EIS'),
                    mtd: !!pick('MTD'),
                    hrd: !!pick('HRD levy'),
                },
                epfCategory: category('EPF'),
                socsoCategory: category('SOCSO'),
                mtdCategory:
                    category('MTD').match(/^category (\d)/)?.[1] ?? 'none',
                mtdChildren: category('MTD').match(/, (\d+) child/)?.[1] ?? '0',
                mtdDisabledSelf: /, disabled(,|$)/.test(category('MTD')),
                mtdDisabledSpouse: category('MTD').includes('disabled spouse'),
                items: (current?.items ?? [])
                    .map((i) => ({
                        type:
                            props.allowanceTypes.find((t) => t.label === i.name)
                                ?.value ?? '',
                        amount: rmText(i.amountSen),
                    }))
                    .filter((i) => i.type !== ''),
                key: crypto.randomUUID(),
            });
        },
    );
    const worksOutMtd = computed(
        () => form.schemes.mtd && ['1', '2', '3'].includes(form.mtdCategory),
    );
    const payTypes: Option[] = [
        { value: 'monthly', label: 'Monthly salary' },
        { value: 'hourly', label: 'Hourly (paid on worked hours)' },
    ];
    const addItem = () => form.items.push({ type: '', amount: '' });
    const removeItem = (index: number) => {
        form.items = form.items.filter((_, i) => i !== index);
    };
    const send = () => {
        if (!props.person) {
            return;
        }

        form.processing = true;
        router.post(
            '/hr/payroll/profiles',
            {
                worker_id: props.person.id,
                pay_group_id: form.payGroup,
                pay_type: form.payType,
                rate: form.rate,
                effective_from: form.effectiveFrom,
                epf: form.schemes.epf,
                epf_category: form.schemes.epf ? form.epfCategory : '',
                socso: form.schemes.socso,
                socso_category: form.schemes.socso ? form.socsoCategory : '',
                eis: form.schemes.eis,
                mtd: form.schemes.mtd,
                hrd: form.schemes.hrd,
                mtd_category: worksOutMtd.value ? form.mtdCategory : '',
                mtd_children: worksOutMtd.value ? form.mtdChildren : '',
                mtd_disabled_self: worksOutMtd.value && form.mtdDisabledSelf,
                mtd_disabled_spouse:
                    form.schemes.mtd &&
                    form.mtdCategory === '2' &&
                    form.mtdDisabledSpouse,
                items: form.items.map((i) => ({
                    item_type_id: i.type,
                    amount: i.amount,
                })),
                idempotency_key: form.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => close(),
                onFinish: () => (form.processing = false),
            },
        );
    };

    return {
        page,
        errors,
        failure,
        schemes,
        form,
        worksOutMtd,
        payTypes,
        addItem,
        removeItem,
        send,
    };
}
