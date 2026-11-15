<script setup lang="ts">
// Version 2 overtime rules card (Director, HR Manager), HR V2 Attendance PR 2: the same form and request as Classic
// through useOvertimeSettings. It reports whether it has unsaved changes; the page decides which card's Save is filled
// (Daniel: only one at a time, the first changed card from the top) and passes `primary` and `othersAbove` back.
import { computed, watch } from 'vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2FormCard from '@/components/v2/V2FormCard.vue';
import { useOvertimeSettings } from '@/composables/hr/useOvertimeSettings';
import { formatRoleLabel } from '@/lib/displayLabels';
import type { OvertimeSettingsCard } from '../types';
import { button, numberClass, primaryButton } from './classes';

const props = defineProps<{
    card: OvertimeSettingsCard;
    today: string;
    primary: boolean;
    othersAbove: boolean;
    othersBelow?: boolean;
}>();
const emit = defineEmits<{ dirty: [value: boolean] }>();

const { errors, form, toggle, save } = useOvertimeSettings(props);

// The saved baseline comes from the server's card (current, else the suggested values), never from the form.
const baseline = () => {
    const start = props.card.current ?? props.card.suggested;

    return {
        normal_multiplier: start.normalMultiplier,
        rest_day_multiplier: start.restDayMultiplier,
        public_holiday_multiplier: start.publicHolidayMultiplier,
        nearing_hours: String(start.nearingHours),
        limit_hours: String(start.limitHours),
        roles: [...(props.card.current?.roles ?? [])],
        effective_from: props.today,
    };
};
const snapshot = (v: ReturnType<typeof baseline>) =>
    JSON.stringify([
        v.normal_multiplier,
        v.rest_day_multiplier,
        v.public_holiday_multiplier,
        v.nearing_hours,
        v.limit_hours,
        [...v.roles].sort(),
        v.effective_from,
    ]);
const dirty = computed(() => snapshot(form) !== snapshot(baseline()));
watch(dirty, (value) => emit('dirty', value), { immediate: true });
const reset = () => Object.assign(form, baseline());
defineExpose({ reset, dirty });
</script>

<template>
    <V2FormCard
        title="Overtime rules"
        :description="
            card.current
                ? `In force since ${card.current.effectiveFrom}.`
                : 'Not set up yet. The rates below are KPOne defaults and no role is ticked; nothing counts until you save.'
        "
        data-test="v2-overtime-rules"
    >
        <p
            v-if="!card.current"
            class="mb-4 text-[13px] text-muted-foreground"
            data-test="overtime-defaults-label"
        >
            Rates 1.5x, 2x and 3x: set by KPOne defaults at the owner's
            instruction; not professional advice. Check against current law
            before real pay.
        </p>
        <form class="flex flex-col gap-4" @submit.prevent="save">
            <fieldset class="flex flex-col gap-2">
                <legend class="mb-1 text-sm font-medium">
                    Roles that may claim overtime
                    <span class="text-destructive" aria-hidden="true">*</span>
                </legend>
                <div class="grid gap-x-4 sm:grid-cols-2">
                    <label
                        v-for="r in card.roleOptions"
                        :key="r.value"
                        class="flex min-h-11 cursor-pointer items-center gap-3 text-sm lg:min-h-9"
                    >
                        <input
                            type="checkbox"
                            class="size-5 accent-primary"
                            :checked="form.roles.includes(r.value)"
                            @change="
                                toggle(
                                    r.value,
                                    ($event.target as HTMLInputElement).checked,
                                )
                            "
                        />
                        {{ formatRoleLabel(r.value) }}
                    </label>
                </div>
                <p
                    v-if="errors.roles"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.roles }}
                </p>
            </fieldset>
            <fieldset class="flex flex-col gap-2">
                <legend class="mb-1 text-sm font-medium">
                    Multipliers (kept for payroll later; no money is worked out
                    here)
                </legend>
                <div class="grid gap-3 sm:grid-cols-3">
                    <V2Field
                        v-slot="{ id, describedBy, invalid }"
                        label="Normal day (×)"
                        required
                        :error="errors.normal_multiplier"
                    >
                        <input
                            :id="id"
                            v-model="form.normal_multiplier"
                            inputmode="decimal"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :class="numberClass"
                        />
                    </V2Field>
                    <V2Field
                        v-slot="{ id, describedBy, invalid }"
                        label="Rest day (×)"
                        required
                        :error="errors.rest_day_multiplier"
                    >
                        <input
                            :id="id"
                            v-model="form.rest_day_multiplier"
                            inputmode="decimal"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :class="numberClass"
                        />
                    </V2Field>
                    <V2Field
                        v-slot="{ id, describedBy, invalid }"
                        label="Public holiday (×)"
                        required
                        :error="errors.public_holiday_multiplier"
                    >
                        <input
                            :id="id"
                            v-model="form.public_holiday_multiplier"
                            inputmode="decimal"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            :class="numberClass"
                        />
                    </V2Field>
                </div>
            </fieldset>
            <div class="grid gap-3 sm:grid-cols-2">
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Warn from (hours a week)"
                    required
                    :error="errors.nearing_hours"
                >
                    <input
                        :id="id"
                        v-model="form.nearing_hours"
                        inputmode="numeric"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="numberClass"
                    />
                </V2Field>
                <V2Field
                    v-slot="{ id, describedBy, invalid }"
                    label="Weekly limit (hours)"
                    required
                    hint="A warning only. It never stops a clock-in or a request."
                    :error="errors.limit_hours"
                >
                    <input
                        :id="id"
                        v-model="form.limit_hours"
                        inputmode="numeric"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :class="numberClass"
                    />
                </V2Field>
            </div>
            <V2DateField
                v-model="form.effective_from"
                label="Applies from"
                required
                hint="Decisions already made keep the settings they used."
                :error="errors.overtime_effective_from"
            />
            <div class="flex flex-col gap-1.5">
                <div>
                    <button
                        type="submit"
                        :class="primary ? primaryButton : button"
                        :disabled="form.processing"
                        data-test="save-overtime-rules"
                    >
                        {{
                            form.processing ? 'Saving…' : 'Save overtime rules'
                        }}
                    </button>
                </div>
                <p
                    v-if="dirty && othersAbove"
                    class="text-[13px] text-muted-foreground"
                >
                    You also have unsaved changes above.
                </p>
                <p
                    v-if="dirty && othersBelow"
                    class="text-[13px] text-muted-foreground"
                    data-test="overtime-others-below"
                >
                    Saving here reloads the page, so the unsaved changes below
                    are lost.
                </p>
            </div>
        </form>
    </V2FormCard>
</template>
