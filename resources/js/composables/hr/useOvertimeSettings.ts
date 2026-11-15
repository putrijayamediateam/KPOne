import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import type { OvertimeSettingsCard } from '@/components/hr/attendance/types';

/**
 * Moved unchanged out of `components/hr/attendance/OvertimeSettingsForm.vue` so Classic and Version 2 share it; the Classic template
 * did not change.
 */
export function useOvertimeSettings(
    props: {
        card: OvertimeSettingsCard;
        today: string;
    },
    // Version 2 keeps the page's state through the save, so another card's unsaved typing survives it; `onSaved` then puts
    // this card back to the saved values (also when the new version starts on a later date).
    options: { preserveState?: boolean; onSaved?: () => void } = {},
) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const start = props.card.current ?? props.card.suggested;
    const form = reactive({
        normal_multiplier: start.normalMultiplier,
        rest_day_multiplier: start.restDayMultiplier,
        public_holiday_multiplier: start.publicHolidayMultiplier,
        nearing_hours: String(start.nearingHours),
        limit_hours: String(start.limitHours),
        roles: [...(props.card.current?.roles ?? [])] as string[],
        effective_from: props.today,
        processing: false,
        key: crypto.randomUUID(),
    });
    const toggle = (role: string, on: boolean) => {
        form.roles = on
            ? [...new Set([...form.roles, role])]
            : form.roles.filter((r) => r !== role);
    };
    const save = () => {
        form.processing = true;
        router.put(
            '/hr/attendance/overtime-settings',
            {
                normal_multiplier: form.normal_multiplier,
                rest_day_multiplier: form.rest_day_multiplier,
                public_holiday_multiplier: form.public_holiday_multiplier,
                nearing_hours: form.nearing_hours,
                limit_hours: form.limit_hours,
                roles: form.roles,
                overtime_effective_from: form.effective_from,
                idempotency_key: form.key,
            },
            {
                preserveScroll: true,
                ...(options.preserveState ? { preserveState: true } : {}),
                onSuccess: () => {
                    form.key = crypto.randomUUID();
                    options.onSaved?.();
                },
                onFinish: () => (form.processing = false),
            },
        );
    };

    return {
        page,
        errors,
        start,
        form,
        toggle,
        save,
    };
}
