<script setup lang="ts">
// HR-3b overtime settings card. KPOne ships no rule: the numbers are the clinic HR proposal's suggestions and no role
// is ticked until HR ticks one; nothing counts until Save.
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatRoleLabel } from '@/lib/displayLabels';
import type { OvertimeSettingsCard } from './types';

const props = defineProps<{ card: OvertimeSettingsCard; today: string }>();

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
// Daniel (GUIDE §19): cobalt only while this form has unsaved changes, or before anything is saved.
const unsaved = computed(() => {
    const c = props.card.current;

    return (
        !c ||
        form.normal_multiplier !== c.normalMultiplier ||
        form.rest_day_multiplier !== c.restDayMultiplier ||
        form.public_holiday_multiplier !== c.publicHolidayMultiplier ||
        form.nearing_hours !== String(c.nearingHours) ||
        form.limit_hours !== String(c.limitHours) ||
        [...form.roles].sort().join() !== [...c.roles].sort().join() ||
        form.effective_from !== props.today
    );
});
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
            onSuccess: () => (form.key = crypto.randomUUID()),
            onFinish: () => (form.processing = false),
        },
    );
};
</script>

<template>
    <section
        aria-labelledby="overtime-settings-title"
        class="flex flex-col gap-4"
        data-test="overtime-settings"
    >
        <h2 id="overtime-settings-title" class="text-base font-semibold">
            Overtime
        </h2>
        <p v-if="!card.current" class="text-sm text-muted-foreground">
            Overtime is not set up yet. The numbers below are suggestions from
            the clinic HR proposal and no role is ticked; nothing counts until
            you save.
        </p>
        <p v-else class="text-sm text-muted-foreground">
            In force since {{ card.current.effectiveFrom }}.
        </p>
        <form
            class="grid max-w-xl gap-4 rounded-xl border bg-card p-4"
            @submit.prevent="save"
        >
            <fieldset class="grid gap-2">
                <legend class="mb-1 text-sm font-medium">
                    Roles that may claim overtime
                </legend>
                <div class="grid gap-x-4 sm:grid-cols-2">
                    <div
                        v-for="r in card.roleOptions"
                        :key="r.value"
                        class="flex min-h-11 items-center gap-2 sm:min-h-9"
                    >
                        <Checkbox
                            :id="`overtime-role-${r.value}`"
                            :model-value="form.roles.includes(r.value)"
                            @update:model-value="
                                (v) => toggle(r.value, v === true)
                            "
                        />
                        <Label :for="`overtime-role-${r.value}`">{{
                            formatRoleLabel(r.value)
                        }}</Label>
                    </div>
                </div>
                <InputError :message="errors.roles" />
            </fieldset>
            <fieldset class="grid gap-3">
                <legend class="mb-1 text-sm font-medium">
                    Multipliers (kept for payroll later; no money is worked out
                    here)
                </legend>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-1">
                        <Label for="overtime-normal">Normal day (×) *</Label>
                        <Input
                            id="overtime-normal"
                            v-model="form.normal_multiplier"
                            inputmode="decimal"
                            class="min-h-11 sm:min-h-9"
                        />
                        <InputError :message="errors.normal_multiplier" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="overtime-rest">Rest day (×) *</Label>
                        <Input
                            id="overtime-rest"
                            v-model="form.rest_day_multiplier"
                            inputmode="decimal"
                            class="min-h-11 sm:min-h-9"
                        />
                        <InputError :message="errors.rest_day_multiplier" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="overtime-holiday"
                            >Public holiday (×) *</Label
                        >
                        <Input
                            id="overtime-holiday"
                            v-model="form.public_holiday_multiplier"
                            inputmode="decimal"
                            class="min-h-11 sm:min-h-9"
                        />
                        <InputError
                            :message="errors.public_holiday_multiplier"
                        />
                    </div>
                </div>
            </fieldset>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1">
                    <Label for="overtime-nearing"
                        >Warn from (hours a week) *</Label
                    >
                    <Input
                        id="overtime-nearing"
                        v-model="form.nearing_hours"
                        inputmode="numeric"
                        class="min-h-11 sm:min-h-9"
                    />
                    <InputError :message="errors.nearing_hours" />
                </div>
                <div class="grid gap-1">
                    <Label for="overtime-limit">Weekly limit (hours) *</Label>
                    <Input
                        id="overtime-limit"
                        v-model="form.limit_hours"
                        inputmode="numeric"
                        class="min-h-11 sm:min-h-9"
                        aria-describedby="overtime-limit-help"
                    />
                    <p
                        id="overtime-limit-help"
                        class="text-xs text-muted-foreground"
                    >
                        A warning only. It never stops a clock-in or a request.
                    </p>
                    <InputError :message="errors.limit_hours" />
                </div>
            </div>
            <div class="grid gap-1">
                <Label for="overtime-effective-from">Applies from *</Label>
                <DateField
                    id="overtime-effective-from"
                    v-model="form.effective_from"
                    aria-describedby="overtime-effective-help"
                />
                <p
                    id="overtime-effective-help"
                    class="text-xs text-muted-foreground"
                >
                    Decisions already made keep the settings they used.
                </p>
                <InputError :message="errors.overtime_effective_from" />
            </div>
            <div>
                <Button
                    type="submit"
                    :variant="unsaved ? 'default' : 'outline'"
                    class="min-h-11 sm:min-h-9"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Saving…' : 'Save overtime settings' }}
                </Button>
            </div>
        </form>
        <div v-if="card.history.length > 0" class="flex flex-col gap-2">
            <h3 class="text-sm font-semibold">Saved overtime versions</h3>
            <ul class="flex flex-col gap-1 text-sm">
                <li v-for="v in card.history" :key="v.id">
                    From {{ v.effectiveFrom }}: {{ v.summary }}
                    <span class="text-muted-foreground">({{ v.by }})</span>
                </li>
            </ul>
        </div>
    </section>
</template>
