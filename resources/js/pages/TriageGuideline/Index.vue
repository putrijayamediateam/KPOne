<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/ui/page-header';
import { StatusBadge } from '@/components/ui/status';

// TRI-1b: the triage guideline settings (Director, HR Manager). One person enters a version from the signed paper
// copy; someone else checks it and approves it. KPOne ships no clinical list. Wording and limits only.
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Clinic management', href: '/clinic-management' },
            { title: 'Triage guideline', href: '/triage-guideline' },
        ],
    },
});

type Limit = {
    reading: string;
    age_band: 'adult' | 'child';
    direction: 'very_low' | 'very_high';
    limit_value: string;
    zone: 'yellow' | 'red';
};
type Detail = {
    id: number;
    versionNumber: number;
    lockVersion: number;
    childAgeLimitYears: number;
    approvingDoctorName: string;
    doctorSignedOn: string;
    enteredBy: string;
    enteredByYou: boolean;
    redFlags: string[];
    yellowSigns: string[];
    limits: Limit[];
};
const props = defineProps<{
    inForce: Detail | null;
    draft: Detail | null;
    history: Array<{
        id: number;
        versionNumber: number;
        status: 'draft' | 'approved' | 'replaced' | 'discarded';
        enteredBy: string;
        enteredAt: string;
        approvedBy: string | null;
        approvedAt: string | null;
    }>;
    readings: string[];
}>();

const READING_LABELS: Record<string, string> = {
    systolic_bp: 'Systolic BP (mmHg)',
    diastolic_bp: 'Diastolic BP (mmHg)',
    pulse_bpm: 'Pulse (bpm)',
    temperature_celsius: 'Temperature (°C)',
    spo2_percent: 'SpO2 (%)',
    weight_kg: 'Weight (kg)',
    height_cm: 'Height (cm)',
};
// Very low red sits furthest from normal, then very low yellow, very high yellow, very high red.
const COLUMNS = [
    { direction: 'very_low', zone: 'red', label: 'Very low · red' },
    { direction: 'very_low', zone: 'yellow', label: 'Very low · yellow' },
    { direction: 'very_high', zone: 'yellow', label: 'Very high · yellow' },
    { direction: 'very_high', zone: 'red', label: 'Very high · red' },
] as const;
const BANDS = [
    { value: 'adult', label: 'Adults' },
    { value: 'child', label: 'Children' },
] as const;
const key = (band: string, reading: string, direction: string, zone: string) =>
    `${band}|${reading}|${direction}|${zone}`;
const limitText = (
    limits: Limit[],
    band: string,
    reading: string,
    direction: string,
    zone: string,
) =>
    limits.find(
        (l) =>
            l.age_band === band &&
            l.reading === reading &&
            l.direction === direction &&
            l.zone === zone,
    )?.limit_value ?? '';
const statusTone = (status: string) =>
    ({
        approved: 'success',
        draft: 'warning',
        replaced: 'neutral',
        discarded: 'neutral',
    })[status] as 'success' | 'warning' | 'neutral';
const statusLabel = (status: string) =>
    ({
        approved: 'In force',
        draft: 'Waiting for approval',
        replaced: 'Replaced',
        discarded: 'Discarded',
    })[status] ?? status;

const emptyLimits = () =>
    Object.fromEntries(
        BANDS.flatMap((b) =>
            props.readings.flatMap((r) =>
                COLUMNS.map((c) => [key(b.value, r, c.direction, c.zone), '']),
            ),
        ),
    ) as Record<string, string>;
const entry = useForm({
    child_age_limit_years: '12',
    approving_doctor_name: '',
    doctor_signed_on: '',
    red_flags: [''] as string[],
    yellow_signs: [] as string[],
    limits: emptyLimits(),
    idempotency_key: crypto.randomUUID(),
});
const startFromInForce = () => {
    const v = props.inForce;

    if (!v) {
        return;
    }

    entry.child_age_limit_years = String(v.childAgeLimitYears);
    entry.red_flags = [...v.redFlags];
    entry.yellow_signs = [...v.yellowSigns];
    const limits = emptyLimits();

    for (const l of v.limits) {
        limits[key(l.age_band, l.reading, l.direction, l.zone)] = l.limit_value;
    }

    entry.limits = limits;
};
const submitEntry = () => {
    entry
        .transform((data) => ({
            child_age_limit_years: data.child_age_limit_years,
            approving_doctor_name: data.approving_doctor_name,
            doctor_signed_on: data.doctor_signed_on,
            red_flags: data.red_flags.map((s) => s.trim()).filter(Boolean),
            yellow_signs: data.yellow_signs
                .map((s) => s.trim())
                .filter(Boolean),
            thresholds: Object.entries(data.limits)
                .filter(([, value]) => value.trim() !== '')
                .map(([k, value]) => {
                    const [age_band, reading, direction, zone] = k.split('|');

                    return {
                        reading,
                        age_band,
                        direction,
                        limit_value: value.trim(),
                        zone,
                    };
                }),
            idempotency_key: data.idempotency_key,
        }))
        .post('/triage-guideline', {
            preserveScroll: true,
            onSuccess: () => {
                entry.reset();
                entry.idempotency_key = crypto.randomUUID();
            },
        });
};
const approval = useForm({
    lock_version: props.draft?.lockVersion ?? 0,
    matches_signed_copy: false,
    idempotency_key: crypto.randomUUID(),
});
const discard = useForm({
    lock_version: props.draft?.lockVersion ?? 0,
    idempotency_key: crypto.randomUUID(),
});
watch(
    () => props.draft?.lockVersion,
    (lockVersion) => {
        approval.lock_version = lockVersion ?? 0;
        discard.lock_version = lockVersion ?? 0;
    },
);
const approveDraft = () =>
    props.draft &&
    approval.post(`/triage-guideline/${props.draft.id}/approve`, {
        preserveScroll: true,
        onSuccess: () => {
            approval.reset();
            approval.idempotency_key = crypto.randomUUID();
        },
    });
const discardDraft = () =>
    props.draft &&
    discard.post(`/triage-guideline/${props.draft.id}/discard`, {
        preserveScroll: true,
        onSuccess: () => {
            discard.idempotency_key = crypto.randomUUID();
        },
    });
const entryErrors = computed(() => entry.errors as Record<string, string>);
const firstError = (prefix: string) =>
    Object.entries(entryErrors.value).find(
        ([k]) => k === prefix || k.startsWith(`${prefix}.`),
    )?.[1];
</script>

<template>
    <Head title="Triage guideline" />
    <main
        class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 pb-28 md:px-8 md:pt-6"
    >
        <PageHeader
            title="Triage guideline"
            description="Red flags, yellow signs and limits from the doctor's signed guideline. One person enters a version; someone else checks it against the signed copy and approves it."
        />

        <template
            v-for="section in [
                {
                    title: 'Version in force',
                    detail: inForce,
                    empty: 'No version is approved yet, so triage records readings only and suggests no zone.',
                },
                {
                    id: 'draft',
                    title: 'Waiting for approval',
                    detail: draft,
                    empty: null,
                },
            ]"
            :key="section.title"
        >
            <section
                v-if="section.detail || section.empty"
                class="rounded-lg border bg-card p-4"
                :aria-labelledby="`heading-${section.id}`"
            >
                <h2
                    :id="`heading-${section.id}`"
                    class="flex flex-wrap items-center gap-2 text-base font-semibold"
                >
                    {{ section.title }}
                    <span
                        v-if="section.detail"
                        class="text-sm font-normal text-muted-foreground"
                        >Version {{ section.detail.versionNumber }}</span
                    >
                </h2>
                <p
                    v-if="!section.detail"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    {{ section.empty }}
                </p>
                <template v-else>
                    <dl
                        class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Signed by
                            </dt>
                            <dd>{{ section.detail.approvingDoctorName }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Signed on
                            </dt>
                            <dd>{{ section.detail.doctorSignedOn }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Child means under
                            </dt>
                            <dd>
                                {{ section.detail.childAgeLimitYears }} years
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Entered by
                            </dt>
                            <dd>{{ section.detail.enteredBy }}</dd>
                        </div>
                    </dl>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <h3 class="text-sm font-medium">Red flags</h3>
                            <ol
                                class="mt-1 list-decimal space-y-1 pl-5 text-sm"
                            >
                                <li
                                    v-for="flag in section.detail.redFlags"
                                    :key="flag"
                                >
                                    {{ flag }}
                                </li>
                            </ol>
                        </div>
                        <div>
                            <h3 class="text-sm font-medium">Yellow signs</h3>
                            <ol
                                v-if="section.detail.yellowSigns.length"
                                class="mt-1 list-decimal space-y-1 pl-5 text-sm"
                            >
                                <li
                                    v-for="sign in section.detail.yellowSigns"
                                    :key="sign"
                                >
                                    {{ sign }}
                                </li>
                            </ol>
                            <p
                                v-else
                                class="mt-1 text-sm text-muted-foreground"
                            >
                                None
                            </p>
                        </div>
                    </div>
                    <div
                        v-for="band in BANDS"
                        :key="band.value"
                        class="mt-4 overflow-x-auto"
                    >
                        <table class="w-full min-w-[520px] text-sm">
                            <caption class="mb-1 text-left text-sm font-medium">
                                {{
                                    band.label
                                }}
                                · limits
                            </caption>
                            <thead
                                class="border-y bg-muted/40 text-left text-xs text-muted-foreground"
                            >
                                <tr>
                                    <th
                                        scope="col"
                                        class="px-2 py-1.5 font-medium"
                                    >
                                        Reading
                                    </th>
                                    <th
                                        v-for="c in COLUMNS"
                                        :key="c.label"
                                        scope="col"
                                        class="px-2 py-1.5 text-right font-medium"
                                    >
                                        {{ c.label }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="r in readings"
                                    :key="r"
                                    class="border-b last:border-b-0"
                                >
                                    <th
                                        scope="row"
                                        class="px-2 py-1.5 text-left font-normal"
                                    >
                                        {{ READING_LABELS[r] }}
                                    </th>
                                    <td
                                        v-for="c in COLUMNS"
                                        :key="c.label"
                                        class="px-2 py-1.5 text-right tabular-nums"
                                    >
                                        {{
                                            limitText(
                                                section.detail.limits,
                                                band.value,
                                                r,
                                                c.direction,
                                                c.zone,
                                            ) || '—'
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div
                        v-if="section.detail === draft && draft"
                        class="mt-5 border-t pt-4"
                    >
                        <p
                            v-if="draft.enteredByYou"
                            role="status"
                            class="text-sm text-muted-foreground"
                        >
                            You entered this version, so someone else must check
                            it against the signed copy and approve it.
                        </p>
                        <form
                            v-else
                            class="space-y-3"
                            @submit.prevent="approveDraft"
                        >
                            <label
                                class="flex min-h-11 items-start gap-3 text-sm lg:min-h-0"
                                ><input
                                    v-model="approval.matches_signed_copy"
                                    type="checkbox"
                                    class="mt-0.5 size-4 accent-primary"
                                />
                                <span
                                    >I have checked every red flag, yellow sign
                                    and limit against the doctor's signed paper
                                    copy, and they match.</span
                                ></label
                            >
                            <InputError
                                :message="
                                    approval.errors.matches_signed_copy ||
                                    approval.errors.lock_version
                                "
                            />
                            <Button
                                type="submit"
                                class="h-11 lg:h-9"
                                :disabled="
                                    approval.processing ||
                                    !approval.matches_signed_copy
                                "
                            >
                                Approve version {{ draft.versionNumber }}
                            </Button>
                        </form>
                        <form class="mt-3" @submit.prevent="discardDraft">
                            <Button
                                type="submit"
                                variant="outline"
                                class="h-11 lg:h-9"
                                :disabled="discard.processing"
                                >Discard this draft</Button
                            >
                            <InputError
                                :message="discard.errors.lock_version"
                            />
                        </form>
                    </div>
                </template>
            </section>
        </template>

        <section
            class="rounded-lg border bg-card p-4"
            aria-labelledby="enter-heading"
        >
            <h2 id="enter-heading" class="text-base font-semibold">
                Enter a new version
            </h2>
            <p v-if="draft" class="mt-2 text-sm text-muted-foreground">
                Version {{ draft.versionNumber }} is waiting for approval.
                Approve or discard it before entering another.
            </p>
            <form
                v-else
                class="mt-3 space-y-5"
                novalidate
                @submit.prevent="submitEntry"
            >
                <p class="text-sm text-muted-foreground">
                    Type it from the signed paper copy. A limit counts at or
                    past its value. The yellow limit must be nearer normal than
                    the red one; leave a box blank for no limit.
                    <button
                        v-if="inForce"
                        type="button"
                        class="underline"
                        @click="startFromInForce"
                    >
                        Start from version {{ inForce.versionNumber }}
                    </button>
                </p>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="grid gap-1">
                        <label for="tg-doctor" class="text-sm"
                            >Signing doctor</label
                        >
                        <input
                            id="tg-doctor"
                            v-model="entry.approving_doctor_name"
                            class="h-11 rounded-md border bg-background px-3 text-sm lg:h-9"
                        />
                        <InputError
                            :message="entry.errors.approving_doctor_name"
                        />
                    </div>
                    <div class="grid gap-1">
                        <label for="tg-signed" class="text-sm">Signed on</label>
                        <input
                            id="tg-signed"
                            v-model="entry.doctor_signed_on"
                            type="date"
                            class="h-11 rounded-md border bg-background px-3 text-sm lg:h-9"
                        />
                        <InputError :message="entry.errors.doctor_signed_on" />
                    </div>
                    <div class="grid gap-1">
                        <label for="tg-child" class="text-sm"
                            >Child means under (years)</label
                        >
                        <input
                            id="tg-child"
                            v-model="entry.child_age_limit_years"
                            inputmode="numeric"
                            class="h-11 rounded-md border bg-background px-3 text-sm lg:h-9"
                        />
                        <InputError
                            :message="entry.errors.child_age_limit_years"
                        />
                    </div>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <fieldset
                        v-for="list in [
                            {
                                field: 'red_flags',
                                title: 'Red flags (1 to 12)',
                                min: 1,
                            },
                            {
                                field: 'yellow_signs',
                                title: 'Yellow signs (up to 12)',
                                min: 0,
                            },
                        ] as const"
                        :key="list.field"
                        class="space-y-2"
                    >
                        <legend class="text-sm font-medium">
                            {{ list.title }}
                        </legend>
                        <div
                            v-for="(_, i) in entry[list.field]"
                            :key="i"
                            class="flex gap-2"
                        >
                            <input
                                v-model="entry[list.field][i]"
                                :aria-label="`${list.field === 'red_flags' ? 'Red flag' : 'Yellow sign'} ${i + 1}`"
                                class="h-11 min-w-0 flex-1 rounded-md border bg-background px-3 text-sm lg:h-9"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                class="h-11 lg:h-9"
                                :disabled="entry[list.field].length <= list.min"
                                :aria-label="`Remove ${list.field === 'red_flags' ? 'red flag' : 'yellow sign'} ${i + 1}`"
                                @click="entry[list.field].splice(i, 1)"
                                >Remove</Button
                            >
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            class="h-11 lg:h-9"
                            :disabled="entry[list.field].length >= 12"
                            @click="entry[list.field].push('')"
                            >Add
                            {{
                                list.field === 'red_flags'
                                    ? 'red flag'
                                    : 'yellow sign'
                            }}</Button
                        >
                        <InputError :message="firstError(list.field)" />
                    </fieldset>
                </div>
                <div
                    v-for="band in BANDS"
                    :key="band.value"
                    class="overflow-x-auto"
                >
                    <table class="w-full min-w-[620px] text-sm">
                        <caption class="mb-1 text-left text-sm font-medium">
                            {{
                                band.label
                            }}
                            · limits
                        </caption>
                        <thead
                            class="border-y bg-muted/40 text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th scope="col" class="px-2 py-1.5 font-medium">
                                    Reading
                                </th>
                                <th
                                    v-for="c in COLUMNS"
                                    :key="c.label"
                                    scope="col"
                                    class="px-2 py-1.5 font-medium"
                                >
                                    {{ c.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="r in readings"
                                :key="r"
                                class="border-b last:border-b-0"
                            >
                                <th
                                    scope="row"
                                    class="px-2 py-1 text-left font-normal"
                                >
                                    {{ READING_LABELS[r] }}
                                </th>
                                <td
                                    v-for="c in COLUMNS"
                                    :key="c.label"
                                    class="px-2 py-1"
                                >
                                    <input
                                        v-model="
                                            entry.limits[
                                                key(
                                                    band.value,
                                                    r,
                                                    c.direction,
                                                    c.zone,
                                                )
                                            ]
                                        "
                                        inputmode="decimal"
                                        :aria-label="`${band.label}, ${READING_LABELS[r]}, ${c.label}`"
                                        class="h-11 w-full min-w-20 rounded-md border bg-background px-2 text-right text-sm tabular-nums lg:h-9"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <InputError
                    :message="
                        firstError('thresholds') || entry.errors.idempotency_key
                    "
                />
                <Button
                    type="submit"
                    class="h-11 lg:h-9"
                    :disabled="entry.processing"
                >
                    {{
                        entry.processing
                            ? 'Saving…'
                            : 'Enter version for approval'
                    }}
                </Button>
            </form>
        </section>

        <section
            class="rounded-lg border bg-card p-4"
            aria-labelledby="history-heading"
        >
            <h2 id="history-heading" class="text-base font-semibold">
                Versions
            </h2>
            <p
                v-if="history.length === 0"
                class="mt-2 text-sm text-muted-foreground"
            >
                No version entered yet.
            </p>
            <ul v-else class="mt-2 divide-y text-sm">
                <li
                    v-for="v in history"
                    :key="v.id"
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2"
                >
                    <span class="font-medium"
                        >Version {{ v.versionNumber }}</span
                    >
                    <StatusBadge
                        :status="v.status"
                        :tone="statusTone(v.status)"
                        :label="statusLabel(v.status)"
                    />
                    <span class="text-muted-foreground"
                        >Entered by {{ v.enteredBy }}, {{ v.enteredAt }}</span
                    >
                    <span v-if="v.approvedBy" class="text-muted-foreground"
                        >Approved by {{ v.approvedBy }},
                        {{ v.approvedAt }}</span
                    >
                </li>
            </ul>
        </section>
        <p class="text-xs text-muted-foreground">
            <Link href="/clinic-management" class="underline"
                >Back to Clinic management</Link
            >
        </p>
    </main>
</template>
