<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import type { ComplianceSettings } from '@/components/hr/compliance/ComplianceRules.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';

/**
 * Version 2 HR-5b rules under Minimum staffing (Classic is `components/hr/compliance/ComplianceRules.vue`, untouched):
 * the same two forms, endpoints, versions and idempotency keys. Each card has its own clearly named Save; a Save is
 * filled only when its card has changed and no earlier card on the page has unsaved changes (`primaryAllowed` is false
 * while the minimums above are changed), so at most one filled button shows at a time (Daniel, 2026-11-13).
 */
const props = defineProps<{
    settings: ComplianceSettings;
    branch: { id: number; name: string };
    week: string;
    primaryAllowed: boolean;
}>();
const emit = defineEmits<{ dirty: [value: boolean] }>();

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
    saved: '',
});
const work = reactive({
    days: '' as string | number,
    rest: '' as string | number,
    week: '' as string | number,
    processing: false,
    key: '',
    saved: '',
});
const coverValue = () => JSON.stringify([cover.hours, String(cover.min)]);
const workValue = () =>
    JSON.stringify([String(work.days), String(work.rest), String(work.week)]);

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
        // Suggested values on a first save count as a change: nothing counts until a manager saves.
        cover.saved = s.coverage.version === 0 ? '' : coverValue();
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
        work.saved = w.version === 0 ? '' : workValue();
    },
    { immediate: true, deep: true },
);

const coverDirty = computed(() => coverValue() !== cover.saved);
const workDirty = computed(() => workValue() !== work.saved);
watch(
    () => coverDirty.value || workDirty.value,
    (d) => emit('dirty', d),
    { immediate: true },
);
const coverFilled = computed(() => props.primaryAllowed && coverDirty.value);
const workFilled = computed(
    () => props.primaryAllowed && !coverDirty.value && workDirty.value,
);

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

const button =
    'inline-flex h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-50 lg:h-9';
const primaryButton =
    'inline-flex h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:opacity-50 lg:h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const numberClass =
    'h-11 w-24 rounded-[10px] border border-input bg-card px-3 text-base tabular-nums outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
</script>

<template>
    <section
        class="flex flex-col gap-4"
        aria-labelledby="v2-compliance-rules"
        data-test="compliance-rules"
    >
        <div>
            <h2 id="v2-compliance-rules" class="text-base font-semibold">
                Compliance rules
            </h2>
            <p class="text-[13px] text-muted-foreground">
                Warnings on the planner only; they never stop saving, sending or
                approving a roster. KPOne ships no number: the form suggests
                values, and nothing counts until you save. Confirm the limits
                with your HR adviser.
            </p>
        </div>
        <p
            v-if="errors.compliance"
            role="alert"
            class="text-sm text-destructive"
        >
            {{ errors.compliance }}
        </p>

        <V2FormCard
            :title="`Opening hours and doctor cover · ${branch.name}`"
            :description="
                settings.coverage.version === 0
                    ? 'Not set yet. Leave both times empty for a closed day.'
                    : `Version ${settings.coverage.version} in force. Leave both times empty for a closed day.`
            "
        >
            <form class="flex flex-col gap-3" @submit.prevent="saveCover">
                <div
                    v-for="[d, label] in days"
                    :key="d"
                    class="grid grid-cols-1 gap-2 sm:grid-cols-[8rem_1fr_1fr] sm:items-end"
                >
                    <p class="text-sm font-medium sm:pb-2.5">{{ label }}</p>
                    <V2Field
                        v-slot="{ id, describedBy, invalid }"
                        label="Opens"
                        :error="errors[`hours.${d}`]"
                    >
                        <input
                            :id="id"
                            v-model="cover.hours[d].open"
                            type="time"
                            :aria-label="`${label} opens`"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :class="inputClass"
                        />
                    </V2Field>
                    <V2Field v-slot="{ id, invalid }" label="Closes">
                        <input
                            :id="id"
                            v-model="cover.hours[d].close"
                            type="time"
                            :aria-label="`${label} closes`"
                            :aria-invalid="invalid"
                            :class="inputClass"
                        />
                    </V2Field>
                </div>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Resident doctors on duty, at least"
                    required
                    hint="0 turns this warning off. A gap of 15 minutes or more is shown."
                    :error="errors.min_resident_doctors"
                >
                    <input
                        :id="id"
                        v-model="cover.min"
                        type="number"
                        inputmode="numeric"
                        min="0"
                        max="20"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="numberClass"
                    />
                </V2Field>
                <div>
                    <button
                        type="submit"
                        :class="coverFilled ? primaryButton : button"
                        :disabled="cover.processing"
                        data-test="coverage-save"
                    >
                        {{
                            cover.processing
                                ? 'Saving…'
                                : 'Save opening hours and doctor cover'
                        }}
                    </button>
                </div>
            </form>
        </V2FormCard>

        <V2FormCard
            title="Work-hour limits · all branches"
            :description="
                settings.workHours.version === 0
                    ? 'Not set yet. Counted across every branch; approved leave counts as a day off. Leave a box empty to turn that warning off.'
                    : `Version ${settings.workHours.version} in force. Counted across every branch; approved leave counts as a day off. Leave a box empty to turn that warning off.`
            "
        >
            <form class="flex flex-col gap-3" @submit.prevent="saveWork">
                <div class="grid gap-3 sm:grid-cols-3">
                    <V2Field
                        v-slot="{ id, describedBy, invalid }"
                        label="Most days in a row"
                        :error="errors.max_days_in_row"
                    >
                        <input
                            :id="id"
                            v-model="work.days"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            max="13"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :class="numberClass"
                        />
                    </V2Field>
                    <V2Field
                        v-slot="{ id, describedBy, invalid }"
                        label="Least rest between shifts (hours)"
                        :error="errors.min_rest_hours"
                    >
                        <input
                            :id="id"
                            v-model="work.rest"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            max="24"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :class="numberClass"
                        />
                    </V2Field>
                    <V2Field
                        v-slot="{ id, describedBy, invalid }"
                        label="Most planned hours a week"
                        :error="errors.max_week_hours"
                    >
                        <input
                            :id="id"
                            v-model="work.week"
                            type="number"
                            inputmode="numeric"
                            min="1"
                            max="100"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :class="numberClass"
                        />
                    </V2Field>
                </div>
                <div>
                    <button
                        type="submit"
                        :class="workFilled ? primaryButton : button"
                        :disabled="work.processing"
                        data-test="work-hours-save"
                    >
                        {{
                            work.processing
                                ? 'Saving…'
                                : 'Save work-hour limits'
                        }}
                    </button>
                </div>
            </form>
        </V2FormCard>
    </section>
</template>
