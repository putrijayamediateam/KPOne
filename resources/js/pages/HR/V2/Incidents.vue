<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2TimeField from '@/components/v2/V2TimeField.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useIncidentsPage } from '@/composables/hr/useIncidentsPage';
import type { IncidentsPageProps } from '@/composables/hr/useIncidentsPage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Incidents (shown only in V2; Classic is `pages/HR/Incidents.vue`, untouched). Same props, routes,
 * permissions and idempotency keys: every request comes from `useIncidentsPage`, which Classic uses too, so this page
 * sends none of its own. The lists carry no description or note text (the props do not hold any); what someone writes
 * in the report form stays in the form until it is sent and is never kept in the browser. A report's text is only on
 * the report page, which this page links to.
 */
const props = defineProps<IncidentsPageProps>();
usePageCrumbs(() => [
    { title: 'KPOne HR', href: '/hr' },
    { title: 'Incidents' },
]);

const {
    errors,
    failure,
    tabs,
    selectTab,
    rows,
    chooser,
    form,
    startReport,
    addPhoto,
    submit,
    textareaClass,
} = useIncidentsPage(props);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const cardClass = 'flex flex-col gap-3 rounded-[14px] border bg-card p-4';
const chipTone = (s: 'reported' | 'acknowledged' | 'closed') =>
    s === 'reported' ? 'warning' : s === 'acknowledged' ? 'info' : 'neutral';

// The date and the time are two fields with their own local values. The one value the shared logic sends
// ("yyyy-mm-ddThh:mm") is set only when BOTH are chosen, so a date alone is still missing and nothing is guessed.
const whenDate = ref('');
const whenTime = ref('');
watch(
    () => form.open,
    (open) => {
        if (open) {
            whenDate.value = form.occurredAt.slice(0, 10);
            whenTime.value = form.occurredAt.slice(11, 16);
        }
    },
);
watch([whenDate, whenTime], ([date, time]) => {
    form.occurredAt = date && time ? `${date}T${time}` : '';
});
// Branch names arrive in capitals (CHERAS); V2 shows them in title case like the rest of V2.
const titleCase = (name: string) =>
    name
        .toLowerCase()
        .replace(/(^|[\s-])([a-z])/g, (_, a, b) => a + b.toUpperCase());
const todayDate = computed(() => props.incidents.today.slice(0, 10));
const branchOptions = computed(() =>
    props.incidents.branches.map((b) => ({
        value: b.id,
        label: titleCase(b.name),
    })),
);
const confirmReason = computed(() =>
    !form.category
        ? 'Choose what kind of incident it was to continue.'
        : !form.occurredAt
          ? 'Choose the date and the time it happened to continue.'
          : whenDate.value > todayDate.value
            ? 'The date cannot be in the future. Choose the date it happened to continue.'
            : form.description.trim().length < 10
              ? 'Describe what happened to continue.'
              : '',
);
</script>

<template>
    <Head title="Incidents" />
    <PageFrame>
        <V2WorkLayout
            title="Incidents"
            description="Report a workplace incident, a near miss or a patient-care concern. HR reads every report; patient-care reports are flagged for clinical review."
        >
            <template v-if="incidents.canReport" #actions>
                <button
                    type="button"
                    :class="primaryButton"
                    data-test="incident-new"
                    @click="startReport"
                >
                    Report an incident
                </button>
            </template>
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <SyntheticNote />
                    <p
                        v-if="failure && !form.open"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ failure }}
                    </p>
                    <V2Tabs
                        v-if="tabs.length > 1"
                        :model-value="incidents.tab"
                        :tabs="tabs"
                        ariaLabel="Incident sections"
                        @update:model-value="selectTab"
                    />
                    <div
                        v-if="rows.length === 0"
                        class="rounded-[14px] border bg-card p-6 text-center"
                    >
                        <p class="font-medium">
                            {{
                                incidents.tab === 'mine'
                                    ? 'You have not reported anything.'
                                    : incidents.tab === 'clinical'
                                      ? 'No reports need clinical review.'
                                      : 'Nothing here.'
                            }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{
                                incidents.tab === 'mine'
                                    ? 'Use Report an incident when something happens. HR sees it at once.'
                                    : 'Reports appear here as staff send them.'
                            }}
                        </p>
                    </div>
                    <ul
                        v-else
                        class="flex flex-col gap-3"
                        data-test="incident-list"
                    >
                        <li
                            v-for="r in rows"
                            :key="r.id"
                            :class="`${cardClass} sm:flex-row sm:items-center sm:justify-between`"
                            data-test="incident-row"
                        >
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-medium">
                                        {{ r.categoryLabel }}
                                    </p>
                                    <V2Chip
                                        :tone="chipTone(r.status)"
                                        :label="r.statusLabel"
                                    />
                                    <V2Chip
                                        v-if="r.needsClinicalReview"
                                        tone="warning"
                                        label="Needs clinical review"
                                    />
                                    <V2Chip
                                        v-if="r.directorOnly"
                                        tone="neutral"
                                        label="Director only"
                                    />
                                </div>
                                <p class="text-sm text-muted-foreground">
                                    {{ titleCase(r.branch) }} · {{ r.occurredAt
                                    }}<template v-if="r.reporter">
                                        · reported by {{ r.reporter }}</template
                                    ><template
                                        v-if="r.own && incidents.tab !== 'mine'"
                                    >
                                        · your own report</template
                                    >
                                </p>
                            </div>
                            <Link
                                :href="`/hr/incidents/${r.id}`"
                                :class="button"
                                >Open<span class="sr-only">
                                    {{ r.categoryLabel }} report from
                                    {{ r.occurredAt }}</span
                                ></Link
                            >
                        </li>
                    </ul>
                </div>
            </template>
        </V2WorkLayout>

        <V2Dialog
            :open="form.open"
            title="Report an incident"
            description="Tell HR what happened. You cannot change a report once it is sent; HR may ask you for more."
            size="tall"
            :confirm-label="form.processing ? 'Sending…' : 'Send report'"
            :busy="form.processing"
            :confirm-disabled="confirmReason !== ''"
            :confirm-disabled-reason="confirmReason"
            data-test="incident-sheet"
            @update:open="(open: boolean) => (form.open = open)"
            @confirm="submit"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <div class="grid gap-1.5">
                <label
                    id="v2-incident-category-label"
                    class="text-sm font-medium"
                    >Kind <span aria-hidden="true">*</span></label
                >
                <OperationalSelect
                    :model-value="form.category"
                    :options="incidents.categories"
                    label="Kind"
                    labelledby="v2-incident-category-label"
                    placeholder="Choose what kind"
                    trigger-class="max-lg:h-11"
                    @update:model-value="(v) => (form.category = String(v))"
                />
                <p
                    v-if="form.category === 'patient_care'"
                    class="text-sm text-muted-foreground"
                    data-test="incident-patient-warning"
                >
                    Patient-care reports are flagged for clinical review, which
                    happens outside KPOne. HR reads this report: do not write
                    the patient's name, IC number or clinical details. Give the
                    visit number only.
                </p>
                <label
                    v-if="form.category === 'staff_conduct'"
                    class="flex min-h-11 items-start gap-2 text-sm"
                >
                    <input
                        v-model="form.directorOnly"
                        type="checkbox"
                        class="mt-1 size-4"
                    />
                    <span class="flex flex-col gap-1">
                        <span class="font-medium"
                            >Send to the Director only</span
                        >
                        <span class="text-muted-foreground"
                            >For example, if this is about the HR Manager. The
                            HR Manager will not see it. You cannot change this
                            after sending.</span
                        >
                    </span>
                </label>
                <p
                    v-if="errors.category ?? errors.director_only"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.category ?? errors.director_only }}
                </p>
            </div>
            <div class="grid gap-1.5">
                <label id="v2-incident-branch-label" class="text-sm font-medium"
                    >Branch</label
                >
                <OperationalSelect
                    :model-value="form.branch"
                    :options="branchOptions"
                    label="Branch"
                    labelledby="v2-incident-branch-label"
                    trigger-class="max-lg:h-11"
                    @update:model-value="(v) => (form.branch = String(v))"
                />
                <p
                    v-if="errors.branch_id"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.branch_id }}
                </p>
            </div>
            <V2DateField
                v-model="whenDate"
                label="Date it happened"
                required
                :max="todayDate"
                :error="errors.occurred_on"
            />
            <V2TimeField
                v-model="whenTime"
                label="Time it happened"
                required
                :error="errors.occurred_time"
            />
            <V2Field
                v-slot="{ id, describedBy }"
                label="What happened"
                required
                :hint="`Do not write a patient's name, IC or diagnosis. ${form.description.length} of ${incidents.maxText} characters.`"
                :error="errors.description"
            >
                <textarea
                    :id="id"
                    v-model="form.description"
                    rows="6"
                    :maxlength="incidents.maxText"
                    :class="textareaClass"
                    :aria-describedby="describedBy"
                ></textarea>
            </V2Field>
            <div class="flex flex-col gap-2">
                <p id="v2-incident-photo-title" class="text-sm font-medium">
                    Photos (optional)
                </p>
                <p
                    id="v2-incident-photo-help"
                    class="text-sm text-muted-foreground"
                >
                    JPG or PNG, up to {{ incidents.maxPhotos }}. Camera and
                    location details are removed. No photos of patients.
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
                        <span class="min-w-0 truncate">{{ p.name }}</span>
                        <button
                            type="button"
                            :class="`${button} size-11 px-0 lg:size-9`"
                            :aria-label="`Remove ${p.name}`"
                            @click="form.photos.splice(i, 1)"
                        >
                            <X class="size-4" aria-hidden="true" />
                        </button>
                    </li>
                </ul>
                <FileChooseButton
                    v-if="form.photos.length < incidents.maxPhotos"
                    ref="chooser"
                    accept="image/jpeg,image/png"
                    :label="
                        form.photos.length ? 'Add another photo' : 'Add a photo'
                    "
                    :status="`${form.photos.length} of ${incidents.maxPhotos} photos`"
                    status-id="v2-incident-photo-state"
                    aria-describedby="v2-incident-photo-help"
                    @change="addPhoto"
                />
                <p
                    v-if="
                        errors.photos ??
                        errors['photos.0'] ??
                        errors['photos.1'] ??
                        errors['photos.2']
                    "
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{
                        errors.photos ??
                        errors['photos.0'] ??
                        errors['photos.1'] ??
                        errors['photos.2']
                    }}
                </p>
            </div>
        </V2Dialog>
    </PageFrame>
</template>
