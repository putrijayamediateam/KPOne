<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { BranchContext, VisitDetail, VisitOptions } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Registration', href: '/registration' }] },
});
const props = defineProps<{ visit: VisitDetail; options: VisitOptions }>();
const page = usePage<{ branchContext: BranchContext }>();
const form = useForm({
    expected_branch_id: page.props.branchContext?.active?.id ?? 0,
    lock_version: props.visit.lockVersion,
    visit_type: props.visit.visitType,
    assigned_doctor_user_id: props.visit.doctor?.id ?? '',
    visit_reason: props.visit.visitReason ?? '',
    coverage_type: props.visit.coverage.type,
    panel_id: props.visit.coverage.panelId ?? '',
    coverage_member_reference: props.visit.coverage.memberReference ?? '',
    priority: props.visit.priority,
});
const submit = () => form.patch(`/visits/${props.visit.visitNumber}`);
const errorFor = (key: string) => (form.errors as Record<string, string>)[key];
</script>

<template>
    <Head :title="`Edit ${visit.visitNumber}`" />
    <main
        class="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4 md:p-6"
    >
        <div class="flex justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">Edit registered Visit</h1>
                <p class="font-mono text-sm text-muted-foreground">
                    {{ visit.visitNumber }} · {{ visit.patient.fullName }}
                </p>
            </div>
            <Button variant="outline" as-child
                ><Link :href="`/visits/${visit.visitNumber}`"
                    ><ArrowLeft class="size-4" /> Back</Link
                ></Button
            >
        </div>
        <form class="space-y-4" @submit.prevent="submit">
            <section class="rounded-lg border bg-card p-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="grid gap-1"
                        ><span class="text-sm font-medium">Visit type</span
                        ><select
                            v-model="form.visit_type"
                            class="h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="consultation">Consultation</option>
                            <option value="otc">OTC</option>
                        </select></label
                    >
                    <label class="grid gap-1"
                        ><span class="text-sm font-medium">Doctor</span
                        ><select
                            v-model="form.assigned_doctor_user_id"
                            class="h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="">No doctor</option>
                            <option
                                v-for="doctor in options.doctors"
                                :key="doctor.id"
                                :value="doctor.id"
                            >
                                {{ doctor.name }}
                            </option></select
                        ><InputError
                            :message="errorFor('assigned_doctor_user_id')"
                    /></label>
                    <label class="grid gap-1 md:col-span-2"
                        ><span class="text-sm font-medium">Visit reason</span
                        ><textarea
                            v-model="form.visit_reason"
                            maxlength="500"
                            rows="3"
                            autocomplete="off"
                            class="rounded-md border bg-background px-3 py-2 text-sm" /><InputError
                            :message="errorFor('visit_reason')"
                    /></label>
                    <label class="grid gap-1"
                        ><span class="text-sm font-medium">Coverage</span
                        ><select
                            v-model="form.coverage_type"
                            class="h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="self_pay">Self-pay</option>
                            <option value="panel">Panel</option>
                        </select></label
                    >
                    <label class="grid gap-1"
                        ><span class="text-sm font-medium">Priority</span
                        ><select
                            v-model="form.priority"
                            class="h-10 rounded-md border bg-background px-3 text-sm"
                        >
                            <option value="normal">Normal</option>
                            <option value="urgent">Urgent</option>
                        </select></label
                    >
                    <template v-if="form.coverage_type === 'panel'"
                        ><label class="grid gap-1"
                            ><span class="text-sm font-medium">Panel</span
                            ><select
                                v-model="form.panel_id"
                                class="h-10 rounded-md border bg-background px-3 text-sm"
                            >
                                <option value="">Select Panel</option>
                                <option
                                    v-for="panel in options.panels"
                                    :key="panel.id"
                                    :value="panel.id"
                                >
                                    {{ panel.name }}
                                </option></select
                            ><InputError
                                :message="errorFor('panel_id')" /></label
                        ><label class="grid gap-1"
                            ><span class="text-sm font-medium"
                                >Member/staff reference</span
                            ><input
                                v-model="form.coverage_member_reference"
                                autocomplete="off"
                                class="h-10 rounded-md border bg-background px-3 text-sm" /></label
                    ></template>
                </div>
            </section>
            <InputError
                :message="
                    errorFor('expected_branch_id') || errorFor('lock_version')
                "
            />
            <div class="flex justify-end">
                <Button type="submit" :disabled="form.processing"
                    >Save Visit</Button
                >
            </div>
        </form>
    </main>
</template>
