<script setup lang="ts">
// HR-7b Runs tab (HR-PLAN 16.9): one card per run with a worded status, and Prepare a run for a pay group and month.
// Managers' own pay and locums stay outside KPOne in v1.
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { OperationalSelect } from '@/components/ui/select';
import { EmptyState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { myr } from '@/types/billing';
import type { Option, RunRow } from './types';
import { on, runWords } from './types';

const props = defineProps<{
    runs: RunRow[];
    months: Option[];
    groups: Option[];
    ready: boolean;
    prepareRefusal: string | null;
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const form = reactive({
    group: props.groups.length === 1 ? props.groups[0].value : '',
    month: props.months[2]?.value ?? '',
    kind: 'regular',
    key: crypto.randomUUID(),
    processing: false,
});
const kinds: Option[] = [
    { value: 'regular', label: 'Monthly run' },
    { value: 'adjustment', label: 'Adjustment to a locked run' },
];
const prepare = () => {
    form.processing = true;
    router.post(
        '/hr/payroll/runs',
        {
            pay_group_id: form.group,
            month: form.month,
            kind: form.kind,
            idempotency_key: form.key,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                form.processing = false;
                form.key = crypto.randomUUID();
            },
        },
    );
};
</script>

<template>
    <section
        aria-labelledby="runs-title"
        class="flex flex-col gap-4"
        data-test="payroll-runs"
    >
        <div class="flex flex-col gap-1">
            <h2 id="runs-title" class="text-base font-semibold">
                Payroll runs
            </h2>
            <p class="text-sm text-muted-foreground">
                In this first version, the Director's and the HR Manager's own
                pay is kept outside KPOne, and locums are paid outside it too.
            </p>
        </div>

        <form
            class="flex flex-col gap-3 rounded-xl border bg-card p-4"
            aria-labelledby="prepare-title"
            data-test="run-prepare"
            @submit.prevent="prepare"
        >
            <h3 id="prepare-title" class="text-sm font-semibold">
                Prepare a run
            </h3>
            <p v-if="!ready" class="text-sm text-muted-foreground">
                Add a pay group, pay profiles and checked tables first.
            </p>
            <p
                v-if="prepareRefusal"
                id="prepare-refusal"
                class="text-sm text-muted-foreground"
            >
                {{ prepareRefusal }}
            </p>
            <div class="flex flex-wrap items-start gap-3">
                <div class="grid min-w-48 gap-1.5">
                    <Label for="run-group"
                        ><span id="run-group-label">Pay group *</span></Label
                    >
                    <OperationalSelect
                        id="run-group"
                        v-model="form.group"
                        labelledby="run-group-label"
                        label="Pay group"
                        placeholder="Choose a pay group"
                        trigger-class="max-lg:h-11"
                        :options="groups"
                        :invalid="!!errors.pay_group_id"
                    />
                    <InputError :message="errors.pay_group_id" />
                </div>
                <div class="grid min-w-40 gap-1.5">
                    <Label for="run-month"
                        ><span id="run-month-label">Month *</span></Label
                    >
                    <OperationalSelect
                        id="run-month"
                        v-model="form.month"
                        labelledby="run-month-label"
                        label="Month"
                        trigger-class="max-lg:h-11"
                        :options="months"
                        :invalid="!!errors.month"
                    />
                    <InputError :message="errors.month" />
                </div>
                <div class="grid min-w-56 gap-1.5">
                    <Label for="run-kind"
                        ><span id="run-kind-label">Kind</span></Label
                    >
                    <OperationalSelect
                        id="run-kind"
                        v-model="form.kind"
                        labelledby="run-kind-label"
                        label="Kind"
                        trigger-class="max-lg:h-11"
                        :options="kinds"
                    />
                </div>
            </div>
            <Button
                type="submit"
                variant="outline"
                class="self-start max-lg:min-h-11"
                :disabled="
                    form.processing || !groups.length || !!prepareRefusal
                "
                :aria-describedby="
                    prepareRefusal ? 'prepare-refusal' : undefined
                "
                data-test="run-prepare-send"
                >{{ form.processing ? 'Preparing…' : 'Prepare run' }}</Button
            >
        </form>

        <EmptyState
            v-if="!runs.length"
            title="No runs yet."
            description="Prepare a run for a pay group and month. Nothing is paid or filed by KPOne."
        />
        <ul v-else class="flex flex-col gap-3">
            <li v-for="r in runs" :key="r.id">
                <Link
                    :href="`/hr/payroll/runs/${r.id}`"
                    class="flex flex-wrap items-start justify-between gap-2 rounded-xl border bg-card p-4 hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    data-test="payroll-run"
                >
                    <span class="min-w-0">
                        <span class="block font-semibold break-words"
                            >{{ r.group }} · {{ r.month
                            }}{{
                                r.kind === 'adjustment' ? ' · adjustment' : ''
                            }}</span
                        >
                        <span class="block text-sm text-muted-foreground"
                            >{{ r.people }}
                            {{ r.people === 1 ? 'person' : 'people' }} · net
                            {{ myr(r.netSen)
                            }}<template v-if="r.errors">
                                · {{ r.errors }}
                                {{
                                    r.errors === 1 ? 'error' : 'errors'
                                }}</template
                            ><template v-if="r.lockedAt">
                                · locked {{ on(r.lockedAt) }}</template
                            ></span
                        >
                    </span>
                    <StatusBadge
                        :status="r.status"
                        :label="runWords[r.status]"
                        :tone="
                            r.status === 'approved' || r.status === 'locked'
                                ? 'success'
                                : 'neutral'
                        "
                    />
                </Link>
            </li>
        </ul>
    </section>
</template>
