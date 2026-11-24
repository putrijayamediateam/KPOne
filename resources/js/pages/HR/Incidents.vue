<script setup lang="ts">
// HR-10d /hr/incidents: report a workplace incident (every human role) and, for the Director and HR Manager, open
// reports, patient-care reports for clinical review, and closed reports. Lists carry no description or note text.
import { Head, Link } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DateTimeField } from '@/components/ui/date-field';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/ui/page-header';
import { OperationalSelect } from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { EmptyState, ErrorState } from '@/components/ui/state';
import { StatusBadge } from '@/components/ui/status';
import { OperationalTabs } from '@/components/ui/tabs';
import { useIncidentsPage } from '@/composables/hr/useIncidentsPage';
import type { IncidentsPageProps } from '@/composables/hr/useIncidentsPage';

const props = defineProps<IncidentsPageProps>();
const {
    errors,
    failure,
    tabs,
    selectTab,
    rows,
    tone,
    chooser,
    form,
    startReport,
    addPhoto,
    missing,
    submit,
    textareaClass,
} = useIncidentsPage(props);
</script>

<template>
    <Head title="Incidents" />
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4 p-4 lg:p-6">
        <PageHeader
            eyebrow="KPOne HR"
            title="Incidents"
            description="Report a workplace incident, a near miss or a patient-care concern. HR reads every report; patient-care reports are flagged for clinical review."
        >
            <template v-if="incidents.canReport" #actions>
                <Button
                    class="max-lg:min-h-11"
                    data-test="incident-new"
                    @click="startReport"
                    >Report an incident</Button
                >
            </template>
        </PageHeader>
        <SyntheticNote />
        <ErrorState v-if="failure && !form.open" :message="failure" />

        <OperationalTabs
            v-if="tabs.length > 1"
            :model-value="incidents.tab"
            :tabs="tabs"
            label="Incident sections"
            class="min-w-0 max-lg:[&>button]:h-11 max-sm:[&>button]:px-2.5"
            @update:model-value="selectTab"
        />

        <EmptyState
            v-if="rows.length === 0"
            :title="
                incidents.tab === 'mine'
                    ? 'You have not reported anything.'
                    : 'Nothing here.'
            "
            :description="
                incidents.tab === 'mine'
                    ? 'Use Report an incident when something happens. HR sees it at once.'
                    : 'Reports appear here as staff send them.'
            "
        />
        <ul
            v-else
            class="divide-y rounded-xl border bg-card"
            data-test="incident-list"
        >
            <li
                v-for="r in rows"
                :key="r.id"
                class="flex flex-wrap items-center justify-between gap-3 p-4"
                data-test="incident-row"
            >
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 font-medium">
                        <span>{{ r.categoryLabel }}</span>
                        <StatusBadge
                            :status="r.status"
                            :label="r.statusLabel"
                            :tone="tone(r.status)"
                        />
                        <StatusBadge
                            v-if="r.needsClinicalReview"
                            status="clinical"
                            label="Needs clinical review"
                            tone="danger"
                        />
                        <StatusBadge
                            v-if="r.directorOnly"
                            status="director-only"
                            label="Director only"
                            tone="neutral"
                        />
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ r.branch }} · {{ r.occurredAt
                        }}<template v-if="r.reporter">
                            · reported by {{ r.reporter }}</template
                        ><template v-if="r.own && incidents.tab !== 'mine'">
                            · your own report</template
                        >
                    </p>
                </div>
                <Button as-child variant="outline" class="max-lg:min-h-11">
                    <Link :href="`/hr/incidents/${r.id}`"
                        >Open<span class="sr-only">
                            {{ r.categoryLabel }} report from
                            {{ r.occurredAt }}</span
                        ></Link
                    >
                </Button>
            </li>
        </ul>

        <Sheet v-model:open="form.open">
            <SheetContent
                class="w-full overflow-y-auto sm:max-w-lg"
                data-test="incident-sheet"
            >
                <SheetHeader>
                    <SheetTitle>Report an incident</SheetTitle>
                    <SheetDescription
                        >Tell HR what happened. You cannot change a report once
                        it is sent; HR may ask you for more.</SheetDescription
                    >
                </SheetHeader>
                <form
                    class="flex flex-col gap-4 px-4 pb-4"
                    @submit.prevent="submit"
                >
                    <ErrorState v-if="failure" :message="failure" />
                    <div class="flex flex-col gap-2">
                        <Label id="incident-category-label">Kind</Label>
                        <OperationalSelect
                            :model-value="form.category"
                            :options="incidents.categories"
                            label="Kind"
                            labelledby="incident-category-label"
                            placeholder="Choose what kind"
                            trigger-class="max-lg:min-h-11"
                            @update:model-value="
                                (v) => (form.category = String(v))
                            "
                        />
                        <p
                            v-if="form.category === 'patient_care'"
                            class="text-sm text-muted-foreground"
                            data-test="incident-patient-warning"
                        >
                            Patient-care reports are flagged for clinical
                            review, which happens outside KPOne. HR reads this
                            report: do not write the patient's name, IC number
                            or clinical details. Give the visit number only.
                        </p>
                        <div
                            v-if="form.category === 'staff_conduct'"
                            class="flex items-start gap-2"
                        >
                            <Checkbox
                                id="incident-director-only"
                                :model-value="form.directorOnly"
                                @update:model-value="
                                    (v) => (form.directorOnly = v === true)
                                "
                            />
                            <div class="flex flex-col gap-1">
                                <Label for="incident-director-only"
                                    >Send to the Director only</Label
                                >
                                <p class="text-sm text-muted-foreground">
                                    For example, if this is about the HR
                                    Manager. The HR Manager will not see it. You
                                    cannot change this after sending.
                                </p>
                            </div>
                        </div>
                        <InputError :message="errors.category" />
                        <InputError :message="errors.director_only" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label id="incident-branch-label">Branch</Label>
                        <OperationalSelect
                            :model-value="form.branch"
                            :options="
                                incidents.branches.map((b) => ({
                                    value: b.id,
                                    label: b.name,
                                }))
                            "
                            label="Branch"
                            labelledby="incident-branch-label"
                            trigger-class="max-lg:min-h-11"
                            @update:model-value="
                                (v) => (form.branch = String(v))
                            "
                        />
                        <InputError :message="errors.branch_id" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label for="incident-when">When it happened</Label>
                        <DateTimeField
                            id="incident-when"
                            v-model="form.occurredAt"
                            :max="incidents.today"
                            :aria-invalid="
                                !!(errors.occurred_on || errors.occurred_time)
                            "
                        />
                        <InputError :message="errors.occurred_on" />
                        <InputError :message="errors.occurred_time" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label for="incident-text">What happened</Label>
                        <textarea
                            id="incident-text"
                            v-model="form.description"
                            rows="6"
                            :maxlength="incidents.maxText"
                            :class="textareaClass"
                            aria-describedby="incident-text-help"
                            :aria-invalid="
                                errors.description ? 'true' : undefined
                            "
                        ></textarea>
                        <p
                            id="incident-text-help"
                            class="text-sm text-muted-foreground"
                        >
                            Do not write a patient's name, IC or diagnosis.
                            {{ form.description.length }} of
                            {{ incidents.maxText }} characters.
                        </p>
                        <InputError :message="errors.description" />
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label for="incident-photo">Photos (optional)</Label>
                        <p
                            id="incident-photo-help"
                            class="text-sm text-muted-foreground"
                        >
                            JPG or PNG, up to {{ incidents.maxPhotos }}. Camera
                            and location details are removed. No photos of
                            patients.
                        </p>
                        <ul
                            v-if="form.photos.length"
                            class="flex flex-col gap-1 text-sm"
                        >
                            <li
                                v-for="(p, i) in form.photos"
                                :key="i"
                                class="flex items-center justify-between gap-2"
                            >
                                <span class="min-w-0 truncate">{{
                                    p.name
                                }}</span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="max-lg:size-11"
                                    :aria-label="`Remove ${p.name}`"
                                    @click="form.photos.splice(i, 1)"
                                    ><X class="size-4" aria-hidden="true"
                                /></Button>
                            </li>
                        </ul>
                        <FileChooseButton
                            v-if="form.photos.length < incidents.maxPhotos"
                            id="incident-photo"
                            ref="chooser"
                            accept="image/jpeg,image/png"
                            :label="
                                form.photos.length
                                    ? 'Add another photo'
                                    : 'Add a photo'
                            "
                            :status="`${form.photos.length} of ${incidents.maxPhotos} photos`"
                            status-id="incident-photo-state"
                            aria-describedby="incident-photo-help"
                            @change="addPhoto"
                        />
                        <InputError
                            :message="
                                errors.photos ??
                                errors['photos.0'] ??
                                errors['photos.1'] ??
                                errors['photos.2']
                            "
                        />
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            type="submit"
                            class="max-lg:min-h-11"
                            :disabled="form.processing || missing !== null"
                            data-test="incident-save"
                            >{{
                                form.processing ? 'Sending…' : 'Send report'
                            }}</Button
                        >
                        <Button
                            type="button"
                            variant="outline"
                            class="max-lg:min-h-11"
                            @click="form.open = false"
                            >Cancel</Button
                        >
                    </div>
                    <p v-if="missing" class="text-sm text-muted-foreground">
                        {{ missing }}
                    </p>
                </form>
            </SheetContent>
        </Sheet>
    </div>
</template>
