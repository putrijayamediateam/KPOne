<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { BadgeCheck, FileClock, Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { PatientDetail, PatientIdentifier } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Patients', href: '/patients' },
            { title: 'Patient record' },
        ],
    },
});
const props = defineProps<{ patient: PatientDetail }>();
const addOpen = ref(false);
const correcting = ref<PatientIdentifier | null>(null);
const identifierForm = useForm({
    identifier_type: 'nric' as 'nric' | 'passport',
    issuing_country_code: 'MY',
    value: '',
});
const identifierError = () =>
    identifierForm.errors.value ??
    (identifierForm.errors as Record<string, string>).identifiers;
const patientUrl = () =>
    '/patients/' + encodeURIComponent(props.patient.patientNumber);
const submitIdentifier = () => {
    const url = correcting.value
        ? patientUrl() + '/identifiers/' + correcting.value.id
        : patientUrl() + '/identifiers';
    const options = {
        onSuccess: () => {
            addOpen.value = false;
            correcting.value = null;
            identifierForm.reset();
        },
    };

    if (correcting.value) {
        identifierForm.patch(url, options);
    } else {
        identifierForm.post(url, options);
    }
};
const startCorrection = (identifier: PatientIdentifier) => {
    correcting.value = identifier;
    identifierForm.identifier_type = identifier.type;
    identifierForm.issuing_country_code = identifier.issuingCountryCode;
    identifierForm.value = '';
    addOpen.value = true;
};
const retire = (identifier: PatientIdentifier) => {
    if (
        confirm(
            'Retire this identifier? The value remains permanently reserved in Patient Master history.',
        )
    ) {
        router.patch(
            patientUrl() + '/identifiers/' + identifier.id + '/retire',
        );
    }
};
const formatTime = (value: string) =>
    new Intl.DateTimeFormat('en-MY', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Kuala_Lumpur',
    }).format(new Date(value));
</script>

<template>
    <Head :title="patient.patientNumber" />
    <main class="flex flex-1 flex-col gap-5 p-4 md:p-7">
        <div
            class="flex flex-col justify-between gap-4 md:flex-row md:items-start"
        >
            <div>
                <div class="font-mono text-sm font-medium text-emerald-800">
                    {{ patient.patientNumber }}
                </div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    {{ patient.identity.fullName }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    Organisation-level Patient Master record
                </p>
            </div>
            <Button v-if="patient.can.update" variant="outline" as-child
                ><Link :href="patientUrl() + '/edit'"
                    ><Pencil class="size-4" /> Edit demographics</Link
                ></Button
            >
        </div>

        <div class="grid gap-5 xl:grid-cols-3">
            <section class="rounded-lg border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">Identity</h2>
                </div>
                <dl class="grid gap-4 p-5 text-sm">
                    <div>
                        <dt class="text-muted-foreground">Date of birth</dt>
                        <dd class="font-medium">
                            {{ patient.identity.dateOfBirth ?? 'Not recorded' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Gender</dt>
                        <dd class="font-medium capitalize">
                            {{ patient.identity.sex }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Nationality</dt>
                        <dd class="font-medium">
                            {{
                                patient.identity.nationalityCode ??
                                'Not recorded'
                            }}
                        </dd>
                    </div>
                </dl>
            </section>
            <section class="rounded-lg border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">Contact</h2>
                </div>
                <dl class="grid gap-4 p-5 text-sm">
                    <div>
                        <dt class="text-muted-foreground">Mobile</dt>
                        <dd class="font-medium">
                            {{ patient.contact.mobilePhone ?? 'Not recorded' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Email</dt>
                        <dd class="font-medium">
                            {{ patient.contact.email ?? 'Not recorded' }}
                        </dd>
                    </div>
                </dl>
            </section>
            <section class="rounded-lg border bg-card">
                <div class="border-b px-5 py-4">
                    <h2 class="font-semibold">Address</h2>
                </div>
                <div class="p-5 text-sm">
                    <template v-if="patient.address.line1"
                        ><p>{{ patient.address.line1 }}</p>
                        <p v-if="patient.address.line2">
                            {{ patient.address.line2 }}
                        </p>
                        <p>
                            {{
                                [patient.address.postcode, patient.address.city]
                                    .filter(Boolean)
                                    .join(' ')
                            }}
                        </p>
                        <p>
                            {{
                                [
                                    patient.address.state,
                                    patient.address.countryCode,
                                ]
                                    .filter(Boolean)
                                    .join(', ')
                            }}
                        </p></template
                    >
                    <p v-else class="text-muted-foreground">Not recorded</p>
                </div>
            </section>
        </div>

        <section class="rounded-lg border bg-card">
            <div
                class="flex items-start justify-between gap-4 border-b px-5 py-4"
            >
                <div>
                    <h2 class="font-semibold">Identifier history</h2>
                    <p class="text-sm text-muted-foreground">
                        Retired values remain reserved and are never
                        overwritten.
                    </p>
                </div>
                <Button
                    v-if="patient.can.update"
                    size="sm"
                    variant="outline"
                    @click="addOpen = !addOpen"
                    ><Plus class="size-4" /> Add first identifier</Button
                >
            </div>
            <form
                v-if="addOpen"
                class="grid gap-3 border-b bg-muted/20 p-5 md:grid-cols-[150px_110px_1fr_auto]"
                @submit.prevent="submitIdentifier"
            >
                <select
                    v-model="identifierForm.identifier_type"
                    :disabled="!!correcting"
                    class="h-10 rounded-md border bg-background px-3 text-sm"
                >
                    <option value="nric">NRIC</option>
                    <option value="passport">Passport</option>
                </select>
                <input
                    v-model="identifierForm.issuing_country_code"
                    :disabled="identifierForm.identifier_type === 'nric'"
                    maxlength="2"
                    class="h-10 rounded-md border bg-background px-3 text-sm uppercase"
                    placeholder="Issuer"
                />
                <input
                    v-model="identifierForm.value"
                    autocomplete="off"
                    class="h-10 rounded-md border bg-background px-3 text-sm"
                    :placeholder="
                        correcting
                            ? 'Corrected identifier value'
                            : 'Identifier value'
                    "
                />
                <Button type="submit" :disabled="identifierForm.processing">{{
                    correcting ? 'Replace' : 'Add'
                }}</Button>
                <InputError
                    class="md:col-span-4"
                    :message="identifierError()"
                />
            </form>
            <div class="divide-y">
                <div
                    v-for="identifier in patient.identifiers"
                    :key="identifier.id"
                    class="flex flex-col justify-between gap-3 px-5 py-4 md:flex-row md:items-center"
                >
                    <div>
                        <div class="flex items-center gap-2 font-medium">
                            <BadgeCheck
                                v-if="identifier.isCurrent"
                                class="size-4 text-emerald-700"
                            />{{ identifier.type.toUpperCase() }} ·
                            {{ identifier.displayValue }}
                        </div>
                        <div class="text-xs text-muted-foreground">
                            {{ identifier.issuingCountryCode }} ·
                            {{
                                identifier.isCurrent
                                    ? 'Current'
                                    : 'Retired ' +
                                      formatTime(identifier.retiredAt!)
                            }}
                        </div>
                    </div>
                    <div
                        v-if="
                            patient.can.manageIdentifiers &&
                            identifier.isCurrent
                        "
                        class="flex gap-2"
                    >
                        <Button
                            size="sm"
                            variant="outline"
                            @click="startCorrection(identifier)"
                            >Correct</Button
                        ><Button
                            size="sm"
                            variant="outline"
                            @click="retire(identifier)"
                            >Retire</Button
                        >
                    </div>
                </div>
                <div
                    v-if="!patient.identifiers.length"
                    class="p-8 text-center text-sm text-muted-foreground"
                >
                    No identifier history recorded.
                </div>
            </div>
        </section>

        <section class="rounded-lg border bg-card">
            <div class="border-b px-5 py-4">
                <h2 class="font-semibold">Administrative metadata</h2>
            </div>
            <div class="grid gap-4 p-5 text-sm md:grid-cols-2">
                <div>
                    <span class="text-muted-foreground">Created</span>
                    <div>
                        {{ formatTime(patient.administrative.createdAt) }}
                    </div>
                </div>
                <div>
                    <span class="text-muted-foreground">Last updated</span>
                    <div>
                        {{ formatTime(patient.administrative.updatedAt) }}
                    </div>
                </div>
            </div>
        </section>

        <section
            v-if="patient.activity.length"
            class="rounded-lg border bg-card"
        >
            <div class="border-b px-5 py-4">
                <h2 class="flex items-center gap-2 font-semibold">
                    <FileClock class="size-4" /> Authorized activity
                </h2>
            </div>
            <div class="divide-y text-sm">
                <div
                    v-for="(event, index) in patient.activity"
                    :key="event.occurredAt + index"
                    class="flex justify-between gap-4 px-5 py-3"
                >
                    <div>
                        <span class="font-medium">{{ event.event }}</span
                        ><span v-if="event.identifierType">
                            · {{ event.identifierType.toUpperCase() }}</span
                        >
                        <div
                            v-if="event.changedFields.length"
                            class="text-xs text-muted-foreground"
                        >
                            {{ event.changedFields.join(', ') }}
                        </div>
                    </div>
                    <div class="text-right text-xs text-muted-foreground">
                        {{ event.actor }}<br />{{
                            formatTime(event.occurredAt)
                        }}
                    </div>
                </div>
            </div>
        </section>
    </main>
</template>
