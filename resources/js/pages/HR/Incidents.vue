<script setup lang="ts">
// HR-10d /hr/incidents: report a workplace incident (every human role) and, for the Director and HR Manager, open
// reports, patient-care reports for clinical review, and closed reports. Lists carry no description or note text.
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import FileChooseButton from '@/components/catalogue/FileChooseButton.vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DateField } from '@/components/ui/date-field';
import { Input } from '@/components/ui/input';
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

type Tab = 'mine' | 'open' | 'clinical' | 'closed';
type Row = {
    id: string;
    category: string;
    categoryLabel: string;
    branch: string;
    occurredAt: string | null;
    status: 'reported' | 'acknowledged' | 'closed';
    statusLabel: string;
    needsClinicalReview: boolean;
    directorOnly: boolean;
    reporter: string | null;
    own: boolean;
};

const props = defineProps<{
    incidents: {
        tab: Tab;
        tabs: Tab[];
        canManage: boolean;
        canReport: boolean;
        counts: Record<Tab, number>;
        mine: Row[];
        reports: Row[];
        branches: { id: string; name: string }[];
        categories: { value: string; label: string }[];
        maxText: number;
        maxPhotos: number;
        today: string;
    };
}>();

const page = usePage();
const errors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);
const failure = computed(() => errors.value.incident ?? errors.value.records);
const tabLabels: Record<Tab, string> = {
    mine: 'My reports',
    open: 'Open',
    clinical: 'Needs clinical review',
    closed: 'Closed',
};
const tabs = computed(() =>
    props.incidents.tabs.map((value) => ({
        value,
        label: `${tabLabels[value]} (${props.incidents.counts[value]})`,
    })),
);
const selectTab = (value: string | number) =>
    router.get('/hr/incidents', { tab: value }, { preserveScroll: true });
const rows = computed(() =>
    props.incidents.tab === 'mine'
        ? props.incidents.mine
        : props.incidents.reports,
);
const tone = (status: Row['status']) =>
    status === 'reported'
        ? 'warning'
        : status === 'acknowledged'
          ? 'accent'
          : 'neutral';

// ---- Report ----
const chooser = ref<{ clear: () => void } | null>(null);
const form = reactive({
    open: false,
    branch: '',
    occurredOn: '',
    occurredTime: '',
    category: '',
    directorOnly: false,
    description: '',
    photos: [] as File[],
    key: crypto.randomUUID(),
    processing: false,
});
const startReport = () => {
    Object.assign(form, {
        open: true,
        branch: props.incidents.branches[0]?.id ?? '',
        occurredOn: props.incidents.today,
        occurredTime: '',
        category: '',
        directorOnly: false,
        description: '',
        photos: [],
        key: crypto.randomUUID(),
    });
};
const addPhoto = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (file && form.photos.length < props.incidents.maxPhotos) {
        form.photos.push(file);
    }

    chooser.value?.clear();
};
const missing = computed(() =>
    !form.category
        ? 'Choose what kind of incident it was.'
        : !form.occurredTime
          ? 'Choose the time it happened.'
          : form.description.trim().length < 10
            ? 'Describe what happened first.'
            : null,
);
const submit = () => {
    form.processing = true;
    router.post(
        '/hr/incidents',
        {
            branch_id: form.branch,
            occurred_on: form.occurredOn,
            occurred_time: form.occurredTime,
            category: form.category,
            director_only:
                form.category === 'staff_conduct' && form.directorOnly ? 1 : 0,
            description: form.description,
            photos: form.photos,
            idempotency_key: form.key,
        },
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => (form.open = false),
            onFinish: () => (form.processing = false),
        },
    );
};
const textareaClass =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-[invalid=true]:border-destructive';
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
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-2">
                            <Label for="incident-date">Date</Label>
                            <DateField
                                id="incident-date"
                                v-model="form.occurredOn"
                                :max="incidents.today"
                                :aria-invalid="!!errors.occurred_on"
                            />
                            <InputError :message="errors.occurred_on" />
                        </div>
                        <div class="flex flex-col gap-2">
                            <Label for="incident-time">Time</Label>
                            <Input
                                id="incident-time"
                                v-model="form.occurredTime"
                                type="time"
                                class="max-lg:min-h-11"
                                :aria-invalid="!!errors.occurred_time"
                            />
                            <InputError :message="errors.occurred_time" />
                        </div>
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
