import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import type { LocationCheckCard } from '@/components/hr/attendance/types';

/**
 * Moved unchanged out of `components/hr/attendance/LocationCheckSettings.vue` so Classic and Version 2 share it; the Classic template
 * did not change.
 */
export function useLocationCheckSettings(
    props: { card: LocationCheckCard },
    // Version 2 keeps the page's state through a save, so the fields refill only when the branch changes (another
    // card's save must not wipe typing here).
    options: { preserveState?: boolean; onSaved?: () => void } = {},
) {
    const page = usePage();
    const errors = computed(
        () => (page.props.errors ?? {}) as Record<string, string | undefined>,
    );
    const onAt = computed(
        () => props.card.branches.find((b) => b.enabled) ?? null,
    );
    const form = reactive({
        branchId: String(onAt.value?.id ?? props.card.branches[0]?.id ?? ''),
        centreLat: '',
        centreLng: '',
        radius: '',
        consentEn: '',
        consentBm: '',
        processing: false,
        confirmOff: false,
        key: crypto.randomUUID(),
    });
    const branch = computed(
        () =>
            props.card.branches.find((b) => String(b.id) === form.branchId) ??
            null,
    );
    const fill = () => {
        const b = branch.value;
        form.centreLat = b?.centreLat ?? '';
        form.centreLng = b?.centreLng ?? '';
        form.radius = String(b?.radiusMetres ?? props.card.suggestedRadius);
        form.consentEn = b?.consentEn ?? props.card.draftConsentEn;
        form.consentBm = b?.consentBm ?? props.card.draftConsentBm;
    };
    fill();
    watch(
        () =>
            options.preserveState
                ? [form.branchId]
                : [form.branchId, props.card],
        fill,
    );

    const branchOptions = computed(() =>
        props.card.branches.map((b) => ({
            value: String(b.id),
            label: b.enabled ? `${b.name} (on)` : b.name,
        })),
    );
    const blockedBy = computed(() =>
        onAt.value && String(onAt.value.id) !== form.branchId
            ? onAt.value
            : null,
    );

    const put = (enabled: boolean) => {
        form.processing = true;
        router.put(
            '/hr/attendance/location-check',
            {
                branch_id: form.branchId,
                enabled,
                centre_lat: form.centreLat,
                centre_lng: form.centreLng,
                radius_metres: form.radius,
                consent_en: form.consentEn,
                consent_bm: form.consentBm,
                idempotency_key: form.key,
            },
            {
                preserveScroll: true,
                ...(options.preserveState ? { preserveState: true } : {}),
                onSuccess: () => {
                    form.key = crypto.randomUUID();
                    form.confirmOff = false;
                    options.onSaved?.();
                },
                onFinish: () => (form.processing = false),
            },
        );
    };

    return {
        page,
        errors,
        onAt,
        form,
        branch,
        fill,
        branchOptions,
        blockedBy,
        put,
    };
}
