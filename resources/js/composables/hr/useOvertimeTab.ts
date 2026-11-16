import { router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import type {
    OvertimeOptions,
    OvertimeRequestRow,
    OvertimeTabData,
} from '@/components/hr/attendance/types';

/**
 * Moved unchanged out of `components/hr/attendance/OvertimeTab.vue` so Classic and Version 2 share it; the Classic template
 * did not change.
 */
export function useOvertimeTab(props: {
    data: OvertimeTabData;
    options: OvertimeOptions;
    error?: string;
}) {
    const monthQuery = computed(() => ({ overtime_month: props.data.month }));
    const visitMonth = (month: string) =>
        router.get(
            '/hr/attendance',
            { tab: 'overtime', month },
            { preserveScroll: true },
        );

    // Daniel: two buttons per card. The Approve dialog starts at the minutes asked; lowering them makes it
    // "Approve fewer minutes" with a required reason, and the primary label follows the minutes.
    type Mode = 'approve' | 'reject' | 'reverse';
    const dialog = reactive({
        open: false,
        mode: 'approve' as Mode,
        target: null as OvertimeRequestRow | null,
        minutes: '',
        reason: '',
        key: '',
        error: '',
        processing: false,
    });
    const open = (row: OvertimeRequestRow, mode: Mode) =>
        Object.assign(dialog, {
            open: true,
            mode,
            target: row,
            minutes: String(row.minutesRequested),
            reason: '',
            key: crypto.randomUUID(),
            error: '',
        });
    const minutesValue = computed(() => {
        const n = Number(dialog.minutes);

        return /^\d+$/.test(dialog.minutes.trim()) ? n : null;
    });
    const fewer = computed(
        () =>
            dialog.mode === 'approve' &&
            !!dialog.target &&
            minutesValue.value !== null &&
            minutesValue.value < dialog.target.minutesRequested,
    );
    const title = computed(() =>
        dialog.mode === 'reverse'
            ? 'Reverse this approved overtime?'
            : dialog.mode === 'reject'
              ? 'Reject this overtime?'
              : fewer.value
                ? 'Approve fewer minutes?'
                : 'Approve this overtime?',
    );
    const confirmLabel = computed(() =>
        dialog.mode === 'reverse'
            ? 'Reverse overtime'
            : dialog.mode === 'reject'
              ? 'Reject overtime'
              : `Approve ${minutesValue.value ?? dialog.target?.minutesRequested ?? ''} min`,
    );
    const needsReason = computed(
        () => dialog.mode !== 'approve' || fewer.value,
    );
    const reasonOptions = computed(() =>
        dialog.mode === 'approve'
            ? props.options.lowerReasons
            : dialog.mode === 'reject'
              ? props.options.rejectReasons
              : props.options.reverseReasons,
    );
    const confirm = () => {
        const target = dialog.target;

        if (!target) {
            return;
        }

        if (dialog.mode === 'approve' && minutesValue.value === null) {
            dialog.error = `Enter whole minutes from 1 to ${target.minutesRequested}.`;

            return;
        }

        if (needsReason.value && dialog.reason === '') {
            dialog.error = fewer.value
                ? 'Choose why fewer minutes are approved.'
                : 'Choose a reason.';

            return;
        }

        const url =
            dialog.mode === 'reverse'
                ? `/hr/attendance/overtime/${target.publicId}/reverse`
                : `/hr/attendance/overtime/${target.publicId}/${dialog.mode}`;
        dialog.processing = true;
        router.post(
            url,
            {
                ...monthQuery.value,
                expected_lock_version: target.lockVersion,
                minutes: dialog.mode === 'approve' ? dialog.minutes : undefined,
                reason: needsReason.value ? dialog.reason : undefined,
                idempotency_key: dialog.key,
            },
            {
                preserveScroll: true,
                onSuccess: () => (dialog.open = false),
                onError: (e) =>
                    (dialog.error =
                        e.overtime ??
                        e.attendance ??
                        e.minutes ??
                        e.reason ??
                        'This could not be saved.'),
                onFinish: () => (dialog.processing = false),
            },
        );
    };

    const withdrawing = ref('');
    const withdraw = (row: OvertimeRequestRow) => {
        if (withdrawing.value !== '') {
            return;
        }

        withdrawing.value = row.publicId;
        router.post(
            `/hr/attendance/overtime/${row.publicId}/withdraw`,
            {
                tab: 'overtime',
                ...monthQuery.value,
                expected_lock_version: row.lockVersion,
                idempotency_key: crypto.randomUUID(),
            },
            { preserveScroll: true, onFinish: () => (withdrawing.value = '') },
        );
    };
    const description = computed(() => {
        const t = dialog.target;

        return t
            ? `${t.person} · ${t.dayLabel} · ${t.dayTypeLabel} · asked for ${t.requestedLabel}`
            : '';
    });

    return {
        monthQuery,
        visitMonth,
        dialog,
        open,
        minutesValue,
        fewer,
        title,
        confirmLabel,
        needsReason,
        reasonOptions,
        confirm,
        withdrawing,
        withdraw,
        description,
    };
}
