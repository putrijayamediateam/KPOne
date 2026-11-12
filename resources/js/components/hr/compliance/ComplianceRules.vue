<script setup lang="ts">
// HR-5b rules (HR-PLAN 12.3 rule 7), shown under Minimum staffing to Director and HR Manager. KPOne ships no number:
// the form only suggests values, and nothing counts until a manager saves. Every save is a new version.
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ErrorState } from '@/components/ui/state';

export type ComplianceSettings = {
    suggested: {
        min_resident_doctors: number;
        max_days_in_row: number;
        min_rest_hours: number;
        max_week_hours: number;
    };
    coverage: {
        version: number;
        hours: Record<string, { open: string; close: string }>;
        minDoctors: number | null;
    };
    workHours: {
        version: number;
        maxDaysInRow: number | null;
        minRestHours: number | null;
        maxWeekHours: number | null;
    };
};

const props = defineProps<{
    settings: ComplianceSettings;
    branch: { id: number; name: string };
    week: string;
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const days = [
    [1, 'Monday'],
    [2, 'Tuesday'],
    [3, 'Wednesday'],
    [4, 'Thursday'],
    [5, 'Friday'],
    [6, 'Saturday'],
    [7, 'Sunday'],
] as const;

const cover = reactive({
    hours: {} as Record<number, { open: string; close: string }>,
    min: '' as string | number,
    processing: false,
    key: '',
});
const work = reactive({
    days: '' as string | number,
    rest: '' as string | number,
    week: '' as string | number,
    processing: false,
    key: '',
});
watch(
    () => props.settings,
    (s) => {
        cover.hours = Object.fromEntries(
            days.map(([d]) => [
                d,
                {
                    open: s.coverage.hours[d]?.open ?? '',
                    close: s.coverage.hours[d]?.close ?? '',
                },
            ]),
        );
        cover.min =
            s.coverage.version === 0
                ? s.suggested.min_resident_doctors
                : (s.coverage.minDoctors ?? 0);
        cover.key = crypto.randomUUID();
        const w = s.workHours;
        work.days =
            w.version === 0
                ? s.suggested.max_days_in_row
                : (w.maxDaysInRow ?? '');
        work.rest =
            w.version === 0
                ? s.suggested.min_rest_hours
                : (w.minRestHours ?? '');
        work.week =
            w.version === 0
                ? s.suggested.max_week_hours
                : (w.maxWeekHours ?? '');
        work.key = crypto.randomUUID();
    },
    { immediate: true, deep: true },
);

// HR forms (Daniel, 2026-11-12): a Save is cobalt only when pressing it would do something (changes, or a first save of a real version).
const coverUnsaved = computed(() => {
    const c = props.settings.coverage;

    if (c.version === 0) {
        return true;
    }

    return (
        String(cover.min) !== String(c.minDoctors ?? 0) ||
        days.some(
            ([d]) =>
                (cover.hours[d]?.open ?? '') !== (c.hours[d]?.open ?? '') ||
                (cover.hours[d]?.close ?? '') !== (c.hours[d]?.close ?? ''),
        )
    );
});
const workUnsaved = computed(() => {
    const w = props.settings.workHours;

    if (w.version === 0) {
        return true;
    }

    return (
        String(work.days) !== String(w.maxDaysInRow ?? '') ||
        String(work.rest) !== String(w.minRestHours ?? '') ||
        String(work.week) !== String(w.maxWeekHours ?? '')
    );
});
const saveCover = () => {
    cover.processing = true;
    router.put(
        '/hr/roster/compliance/coverage',
        {
            branch_id: props.branch.id,
            week: props.week,
            hours: cover.hours,
            min_resident_doctors: cover.min,
            expected_version: props.settings.coverage.version,
            idempotency_key: cover.key,
        },
        { preserveScroll: true, onFinish: () => (cover.processing = false) },
    );
};
const saveWork = () => {
    work.processing = true;
    router.put(
        '/hr/roster/compliance/work-hours',
        {
            branch_id: props.branch.id,
            week: props.week,
            max_days_in_row: work.days,
            min_rest_hours: work.rest,
            max_week_hours: work.week,
            expected_version: props.settings.workHours.version,
            idempotency_key: work.key,
        },
        { preserveScroll: true, onFinish: () => (work.processing = false) },
    );
};
</script>

<template>
    <section
        aria-labelledby="compliance-rules-title"
        class="flex flex-col gap-4 border-t pt-4"
        data-test="compliance-rules"
    >
        <div>
            <h2 id="compliance-rules-title" class="text-base font-semibold">
                Compliance rules
            </h2>
            <p class="text-xs text-muted-foreground">
                Warnings on the planner only; they never stop saving, sending or
                approving a roster. KPOne ships no number: the form suggests
                values, and nothing counts until you save. Confirm the limits
                with your HR adviser.
            </p>
        </div>
        <ErrorState v-if="errors.compliance" :message="errors.compliance" />
        <div class="grid gap-4 lg:grid-cols-2">
            <form
                class="flex flex-col gap-3 rounded-xl border bg-card p-4"
                data-test="coverage-form"
                @submit.prevent="saveCover"
            >
                <h3 class="text-sm font-semibold">
                    Opening hours and doctor cover · {{ branch.name }}
                </h3>
                <p class="text-xs text-muted-foreground">
                    {{
                        settings.coverage.version === 0
                            ? 'Not set yet.'
                            : `Version ${settings.coverage.version} in force.`
                    }}
                    Leave both times empty for a closed day. A gap of 15 minutes
                    or more is shown.
                </p>
                <div
                    class="grid grid-cols-2 gap-2 text-xs text-muted-foreground sm:grid-cols-[6.5rem_1fr_1fr]"
                    aria-hidden="true"
                >
                    <span class="hidden sm:block">Day</span>
                    <span>Opens</span>
                    <span>Closes</span>
                </div>
                <div
                    v-for="[d, label] in days"
                    :key="d"
                    class="grid grid-cols-2 items-center gap-2 sm:grid-cols-[6.5rem_1fr_1fr]"
                >
                    <span
                        class="col-span-2 text-sm font-medium sm:col-span-1 sm:font-normal"
                        >{{ label }}</span
                    >
                    <Input
                        v-model="cover.hours[d].open"
                        type="time"
                        class="max-lg:h-11"
                        :aria-label="`${label} opens`"
                        :aria-invalid="
                            errors[`hours.${d}`] ? 'true' : undefined
                        "
                    />
                    <Input
                        v-model="cover.hours[d].close"
                        type="time"
                        class="max-lg:h-11"
                        :aria-label="`${label} closes`"
                        :aria-invalid="
                            errors[`hours.${d}`] ? 'true' : undefined
                        "
                    />
                    <InputError
                        v-if="errors[`hours.${d}`]"
                        class="col-span-2 sm:col-span-3"
                        :message="errors[`hours.${d}`]"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="coverage-min"
                        >Resident doctors on duty, at least *</Label
                    >
                    <Input
                        id="coverage-min"
                        v-model="cover.min"
                        type="number"
                        min="0"
                        max="20"
                        inputmode="numeric"
                        class="w-28 max-lg:h-11"
                    />
                    <p class="text-xs text-muted-foreground">
                        0 turns this warning off.
                    </p>
                    <InputError :message="errors.min_resident_doctors" />
                </div>
                <Button
                    type="submit"
                    :variant="coverUnsaved ? 'default' : 'outline'"
                    class="self-start max-lg:min-h-11"
                    :disabled="cover.processing"
                    data-test="coverage-save"
                    >{{
                        cover.processing ? 'Saving…' : 'Save doctor cover'
                    }}</Button
                >
            </form>

            <form
                class="flex flex-col gap-3 self-start rounded-xl border bg-card p-4"
                data-test="work-hours-form"
                @submit.prevent="saveWork"
            >
                <h3 class="text-sm font-semibold">
                    Work-hour limits · all branches
                </h3>
                <p class="text-xs text-muted-foreground">
                    {{
                        settings.workHours.version === 0
                            ? 'Not set yet.'
                            : `Version ${settings.workHours.version} in force.`
                    }}
                    Counted across every branch; approved leave counts as a day
                    off. Leave a box empty to turn that warning off.
                </p>
                <div class="grid gap-1.5">
                    <Label for="work-days">Most days in a row</Label>
                    <Input
                        id="work-days"
                        v-model="work.days"
                        type="number"
                        min="1"
                        max="13"
                        inputmode="numeric"
                        class="w-28 max-lg:h-11"
                    />
                    <InputError :message="errors.max_days_in_row" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="work-rest"
                        >Least rest between shifts (hours)</Label
                    >
                    <Input
                        id="work-rest"
                        v-model="work.rest"
                        type="number"
                        min="1"
                        max="24"
                        inputmode="numeric"
                        class="w-28 max-lg:h-11"
                    />
                    <InputError :message="errors.min_rest_hours" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="work-week">Most planned hours a week</Label>
                    <Input
                        id="work-week"
                        v-model="work.week"
                        type="number"
                        min="1"
                        max="100"
                        inputmode="numeric"
                        class="w-28 max-lg:h-11"
                    />
                    <InputError :message="errors.max_week_hours" />
                </div>
                <Button
                    type="submit"
                    :variant="workUnsaved ? 'default' : 'outline'"
                    class="self-start max-lg:min-h-11"
                    :disabled="work.processing"
                    data-test="work-hours-save"
                    >{{
                        work.processing ? 'Saving…' : 'Save work-hour limits'
                    }}</Button
                >
            </form>
        </div>
    </section>
</template>
