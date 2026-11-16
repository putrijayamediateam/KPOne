<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SyntheticNote from '@/components/hr/records/SyntheticNote.vue';
import { PageFrame } from '@/components/ui/page-frame';
import { OperationalSelect } from '@/components/ui/select';
import V2Chip from '@/components/v2/V2Chip.vue';
import V2DateField from '@/components/v2/V2DateField.vue';
import V2Dialog from '@/components/v2/V2Dialog.vue';
import V2Field from '@/components/v2/V2Field.vue';
import V2OptionGroup from '@/components/v2/V2OptionGroup.vue';
import V2Tabs from '@/components/v2/V2Tabs.vue';
import V2WorkLayout from '@/components/v2/V2WorkLayout.vue';
import { useLettersPage } from '@/composables/hr/useLettersPage';
import type { LettersPageProps } from '@/composables/hr/useLettersPage';
import { usePageCrumbs } from '@/composables/usePageCrumbs';

/**
 * Version 2 Letters (shown only in V2; Classic is `pages/HR/Letters.vue`, untouched). Same props, routes, permissions,
 * lock versions and idempotency keys: every request comes from `useLettersPage`, which Classic uses too, so this page
 * sends none of its own. The text of an issued letter is never on this page: only the server's preview of a letter about
 * to be issued is fetched, on a press of Preview, and any change to the choices clears it. A letter is printed, signed by
 * hand and given in person; nothing is sent. An issued letter is never edited: replace it or void it.
 */
const props = defineProps<LettersPageProps>();
usePageCrumbs(() => [{ title: 'KPOne HR', href: '/hr' }, { title: 'Letters' }]);

const {
    errors,
    failure,
    issuedLetter,
    statusLabel,
    tabs,
    selectTab,
    letterUrl,
    option,
    fields,
    activeTemplates,
    workerOptions,
    issue,
    chosenTemplate,
    templateOptions,
    startIssue,
    preview,
    loadPreview,
    workerName,
    sendIssue,
    voiding,
    voidReason,
    voidBusy,
    askVoid,
    sendVoid,
    giving,
    givenOn,
    givenBusy,
    askGiven,
    sendGiven,
    tpl,
    startTemplate,
    detailsAllowed,
    saveTemplate,
    retiring,
    retireBusy,
    askRetire,
    sendRetire,
} = useLettersPage(props);

const button =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] border border-border bg-card px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const dangerButton = `${button} text-destructive`;
const primaryButton =
    'inline-flex min-h-11 items-center justify-center rounded-[10px] bg-primary px-4 text-sm font-medium whitespace-nowrap text-primary-foreground hover:bg-primary/90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 lg:min-h-9';
const inputClass =
    'h-11 w-full rounded-[10px] border border-input bg-card px-3 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px] lg:h-9';
const textareaClass =
    'w-full rounded-[10px] border border-input bg-card px-3 py-2 text-base outline-none hover:border-foreground/60 focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring md:text-[15px]';
const cardClass = 'flex flex-col gap-3 rounded-[14px] border bg-card p-4';

// The manager sentence belongs to the manager tabs only (Daniel).
const description = computed(() =>
    props.letters.canIssue
        ? 'Write letter templates and issue numbered letters to a staff file. A letter is printed, signed by hand and given in person; nothing is sent. An issued letter is never edited: replace it or void it.'
        : 'Letters HR issued to you. Open one to read or print it.',
);
const languages = [
    { value: 'en', label: 'English' },
    { value: 'bm', label: 'Malay' },
];
const issueMissing = computed(() => {
    if (!issue.worker) {
        return 'Choose a staff file to continue.';
    }

    if (!issue.template) {
        return 'Choose a template to continue.';
    }

    if (chosenTemplate.value?.hasDetails && issue.details.trim() === '') {
        return 'Write the paragraph for this letter to continue.';
    }

    return preview.text === null ? 'Preview the letter to continue.' : '';
});
const previewMissing = computed(
    () => !issue.worker || !issue.template || preview.busy,
);
const voidMissing = computed(() =>
    voidReason.value === '' ? 'Pick a reason to continue.' : '',
);
const givenMissing = computed(() =>
    givenOn.value === '' ? 'Choose the date to continue.' : '',
);
const tplMissing = computed(() => {
    if (!tpl.id && tpl.type === '') {
        return 'Choose the kind of letter to continue.';
    }

    if (tpl.title.trim() === '' || tpl.titleBm.trim() === '') {
        return 'Enter the English and Malay titles to continue.';
    }

    return tpl.bodyEn.trim() === '' || tpl.bodyBm.trim() === ''
        ? 'Write the English and Malay text to continue.'
        : '';
});
const issueDialogOpen = computed(() => issue.open);
</script>

<template>
    <Head title="Letters" />
    <PageFrame>
        <V2WorkLayout title="Letters" :description="description">
            <template v-if="letters.tab === 'issued'" #actions>
                <span
                    v-if="!activeTemplates.length"
                    id="v2-issue-hint"
                    class="self-center text-sm text-muted-foreground"
                    >Add a template first.</span
                >
                <button
                    type="button"
                    :class="primaryButton"
                    :disabled="!activeTemplates.length"
                    :aria-describedby="
                        activeTemplates.length ? undefined : 'v2-issue-hint'
                    "
                    data-test="letter-issue"
                    @click="startIssue()"
                >
                    Issue a letter
                </button>
            </template>
            <template v-else-if="letters.tab === 'templates'" #actions>
                <button
                    type="button"
                    :class="primaryButton"
                    data-test="template-new"
                    @click="startTemplate()"
                >
                    New template
                </button>
            </template>
            <template #main>
                <div class="flex min-w-0 flex-col gap-4">
                    <SyntheticNote />
                    <V2Tabs
                        v-if="tabs.length > 1"
                        :model-value="letters.tab"
                        :tabs="tabs"
                        ariaLabel="Letter sections"
                        @update:model-value="selectTab"
                    />
                    <p
                        v-if="
                            failure &&
                            !issue.open &&
                            !tpl.open &&
                            !voiding &&
                            !giving
                        "
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ failure }}
                    </p>
                    <div
                        v-if="issuedLetter"
                        :class="`${cardClass} sm:flex-row sm:items-center sm:justify-between`"
                        role="status"
                        data-test="letter-issued"
                    >
                        <p class="text-sm">
                            The letter is issued. Open it to print, then sign it
                            and give it by hand.
                        </p>
                        <Link :href="letterUrl(issuedLetter)" :class="button"
                            >Open to print</Link
                        >
                    </div>

                    <!-- My letters -->
                    <section
                        v-if="letters.tab === 'mine'"
                        aria-labelledby="v2-my-letters-title"
                        class="flex flex-col gap-3"
                        data-test="my-letters"
                    >
                        <h2 id="v2-my-letters-title" class="sr-only">
                            My letters
                        </h2>
                        <div
                            v-if="!letters.mine.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">No letters for you.</p>
                            <p class="text-sm text-muted-foreground">
                                When HR issues a letter to you, it appears here.
                            </p>
                        </div>
                        <ul v-else class="flex flex-col gap-3">
                            <li
                                v-for="letter in letters.mine"
                                :key="letter.id"
                                :class="`${cardClass} sm:flex-row sm:items-center sm:justify-between`"
                                data-test="my-letter"
                            >
                                <div class="min-w-0">
                                    <p class="font-medium break-words">
                                        {{ letter.title }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ letter.reference }} · issued
                                        {{ letter.issuedOn }}
                                        <template v-if="letter.givenOn">
                                            · given
                                            {{ letter.givenOn }}</template
                                        ><template v-if="letter.replacedBy">
                                            · replaced by
                                            {{ letter.replacedBy }}</template
                                        >
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <V2Chip
                                        v-if="letter.status !== 'current'"
                                        tone="neutral"
                                        :label="statusLabel[letter.status]"
                                    />
                                    <Link
                                        :href="letterUrl(letter.id)"
                                        :class="button"
                                        :aria-label="`Open ${letter.title}, ${letter.reference}`"
                                        >Open</Link
                                    >
                                </div>
                            </li>
                        </ul>
                    </section>

                    <!-- Issued letters -->
                    <section
                        v-if="letters.tab === 'issued'"
                        aria-labelledby="v2-issued-title"
                        class="flex flex-col gap-3"
                        data-test="issued-letters"
                    >
                        <h2
                            id="v2-issued-title"
                            class="text-base font-semibold"
                        >
                            Issued letters
                        </h2>
                        <div
                            v-if="!letters.issued.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">No letters issued yet.</p>
                            <p class="text-sm text-muted-foreground">
                                Add a template, then issue a letter to a staff
                                file.
                            </p>
                        </div>
                        <ul v-else class="flex flex-col gap-3">
                            <li
                                v-for="letter in letters.issued"
                                :key="letter.id"
                                :class="cardClass"
                                data-test="issued-letter"
                            >
                                <div class="min-w-0">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <p class="font-medium break-words">
                                            {{ letter.recipient }} ·
                                            {{ letter.title }}
                                        </p>
                                        <V2Chip
                                            v-if="letter.status !== 'current'"
                                            tone="neutral"
                                            :label="statusLabel[letter.status]"
                                        />
                                        <V2Chip
                                            v-else
                                            :tone="
                                                letter.givenOn
                                                    ? 'success'
                                                    : 'neutral'
                                            "
                                            :label="
                                                letter.givenOn
                                                    ? 'Given'
                                                    : 'Waiting to be given'
                                            "
                                            data-test="letter-given-state"
                                        />
                                    </div>
                                    <p class="text-sm text-muted-foreground">
                                        {{ letter.reference }} ·
                                        {{ letter.typeLabel }} ·
                                        {{ letter.language }} · issued
                                        {{ letter.issuedOn }} by
                                        {{ letter.issuedBy
                                        }}<template v-if="letter.givenOn">
                                            · given
                                            {{ letter.givenOn }}</template
                                        ><template v-if="letter.voidReason">
                                            · void ({{
                                                letter.voidReason
                                            }})</template
                                        ><template v-if="letter.replacedBy">
                                            · replaced by
                                            {{ letter.replacedBy }}</template
                                        >
                                    </p>
                                    <p
                                        v-if="letter.refusal"
                                        class="text-sm text-muted-foreground"
                                    >
                                        {{ letter.refusal }}
                                    </p>
                                </div>
                                <div
                                    v-if="!letter.refusal"
                                    class="flex flex-wrap items-center gap-2"
                                >
                                    <Link
                                        :href="letterUrl(letter.id)"
                                        :class="button"
                                        :aria-label="`Open ${letter.reference}`"
                                        >Open</Link
                                    >
                                    <template
                                        v-if="letter.status === 'current'"
                                    >
                                        <button
                                            v-if="!letter.givenOn"
                                            type="button"
                                            :class="button"
                                            :aria-label="`Record when ${letter.reference} was given`"
                                            data-test="letter-given"
                                            @click="askGiven(letter)"
                                        >
                                            Record given
                                        </button>
                                        <button
                                            type="button"
                                            :class="button"
                                            :disabled="!activeTemplates.length"
                                            :aria-label="`Replace ${letter.reference}`"
                                            data-test="letter-replace"
                                            @click="startIssue(letter)"
                                        >
                                            Replace
                                        </button>
                                        <button
                                            type="button"
                                            :class="dangerButton"
                                            :aria-label="`Void ${letter.reference}`"
                                            data-test="letter-void"
                                            @click="askVoid(letter)"
                                        >
                                            Void
                                        </button>
                                    </template>
                                </div>
                            </li>
                        </ul>
                    </section>

                    <!-- Templates -->
                    <section
                        v-if="letters.tab === 'templates'"
                        aria-labelledby="v2-templates-title"
                        class="flex flex-col gap-3"
                        data-test="letter-templates"
                    >
                        <h2
                            id="v2-templates-title"
                            class="text-base font-semibold"
                        >
                            Templates
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            KPOne ships no letter wording. Have the clinic's
                            adviser approve each template before real use.
                        </p>
                        <div
                            v-if="!letters.templates.length"
                            class="rounded-[14px] border bg-card p-6 text-center"
                        >
                            <p class="font-medium">No templates yet.</p>
                            <p class="text-sm text-muted-foreground">
                                Write the clinic's own wording in English and
                                Malay.
                            </p>
                        </div>
                        <ul v-else class="flex flex-col gap-3">
                            <li
                                v-for="template in letters.templates"
                                :key="template.id"
                                :class="`${cardClass} sm:flex-row sm:items-center sm:justify-between`"
                                data-test="letter-template"
                            >
                                <div class="min-w-0">
                                    <p class="font-medium break-words">
                                        {{ template.title }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ template.typeLabel }} · version
                                        {{ template.version }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <V2Chip
                                        v-if="template.retired"
                                        tone="neutral"
                                        label="Retired"
                                    />
                                    <template v-else>
                                        <button
                                            type="button"
                                            :class="button"
                                            :aria-label="`Edit ${template.title}`"
                                            @click="startTemplate(template)"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            :class="dangerButton"
                                            :aria-label="`Retire ${template.title}`"
                                            @click="askRetire(template)"
                                        >
                                            Retire
                                        </button>
                                    </template>
                                </div>
                            </li>
                        </ul>
                    </section>
                </div>
            </template>
        </V2WorkLayout>

        <!-- Issue a letter: the preview is the check; Issue letter stays off until it has been made -->
        <V2Dialog
            :open="issueDialogOpen"
            :title="
                issue.replaces
                    ? `Replace ${issue.replacesReference}`
                    : 'Issue a letter'
            "
            :description="
                issue.replaces
                    ? 'The new letter gets the next number, and the old one becomes void as replaced. Both are kept.'
                    : 'Check the preview, then issue. The letter gets the next number and cannot be edited.'
            "
            size="tall"
            :confirm-label="
                issue.processing
                    ? 'Issuing…'
                    : issue.replaces
                      ? 'Issue replacement'
                      : 'Issue letter'
            "
            :busy="issue.processing"
            :confirm-disabled="issueMissing !== ''"
            :confirm-disabled-reason="issueMissing"
            data-test="issue-sheet"
            @update:open="(open: boolean) => (issue.open = open)"
            @confirm="sendIssue"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <div class="grid gap-1.5">
                <label id="v2-issue-worker-label" class="text-sm font-medium"
                    >Staff file <span aria-hidden="true">*</span></label
                >
                <OperationalSelect
                    :model-value="issue.worker"
                    :options="workerOptions"
                    label="Staff file"
                    labelledby="v2-issue-worker-label"
                    placeholder="Choose a staff file"
                    :disabled="!!issue.replaces"
                    trigger-class="max-lg:h-11"
                    @update:model-value="(v) => (issue.worker = String(v))"
                />
                <p
                    v-if="errors.worker_id"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.worker_id }}
                </p>
            </div>
            <div class="grid gap-1.5">
                <label id="v2-issue-template-label" class="text-sm font-medium"
                    >Template <span aria-hidden="true">*</span></label
                >
                <OperationalSelect
                    :model-value="issue.template"
                    :options="templateOptions"
                    label="Template"
                    labelledby="v2-issue-template-label"
                    placeholder="Choose a template"
                    trigger-class="max-lg:h-11"
                    @update:model-value="(v) => (issue.template = String(v))"
                />
                <p
                    v-if="errors.template_id"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.template_id }}
                </p>
            </div>
            <V2OptionGroup
                v-model="issue.language"
                :options="languages"
                label="Language"
                required
                :error="errors.language"
            />
            <V2Field
                v-if="chosenTemplate?.hasDetails"
                v-slot="{ id, describedBy }"
                label="Paragraph for this letter"
                required
                :hint="`It fills {details} in the template. Plain text, ${issue.details.length} of 2,000 characters.`"
                :error="errors.details"
            >
                <textarea
                    :id="id"
                    v-model="issue.details"
                    rows="6"
                    maxlength="2000"
                    :class="textareaClass"
                    :aria-describedby="describedBy"
                ></textarea>
            </V2Field>
            <div>
                <button
                    type="button"
                    :class="button"
                    :disabled="previewMissing"
                    data-test="letter-preview"
                    @click="loadPreview"
                >
                    {{ preview.busy ? 'Checking…' : 'Preview' }}
                </button>
            </div>
            <p
                v-if="preview.error"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ preview.error }}
            </p>
            <div
                v-if="preview.text !== null"
                class="flex flex-col gap-2 rounded-[10px] border bg-muted/40 p-3"
                data-test="letter-preview-text"
            >
                <p class="text-sm font-semibold">{{ preview.title }}</p>
                <p class="text-sm break-words whitespace-pre-wrap">
                    {{ preview.text }}
                </p>
                <p class="text-xs text-muted-foreground">
                    The number replaces "……" when you issue. This
                    {{ issue.replaces ? 'replacement' : 'letter' }} is for
                    {{ workerName }}.
                </p>
            </div>
        </V2Dialog>

        <!-- Void a letter -->
        <V2Dialog
            :open="voiding !== null"
            title="Void this letter?"
            :description="
                voiding
                    ? `${voiding.reference} for ${voiding.recipient}. It stays on record as void with its reason, and the number is never used again.`
                    : ''
            "
            confirm-label="Void"
            tone="danger"
            :busy="voidBusy"
            :confirm-disabled="voidMissing !== ''"
            :confirm-disabled-reason="voidMissing"
            @update:open="(open: boolean) => !open && (voiding = null)"
            @confirm="sendVoid"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <V2OptionGroup
                :model-value="voidReason || null"
                :options="option(letters.voidReasons)"
                label="Why"
                required
                :error="errors.reason"
                @update:model-value="voidReason = $event ?? ''"
            />
        </V2Dialog>

        <!-- Record when it was given -->
        <V2Dialog
            :open="giving !== null"
            title="Record when it was given"
            :description="
                giving
                    ? `${giving.reference} for ${giving.recipient}. Recorded once.`
                    : ''
            "
            confirm-label="Record"
            :busy="givenBusy"
            :confirm-disabled="givenMissing !== ''"
            :confirm-disabled-reason="givenMissing"
            @update:open="(open: boolean) => !open && (giving = null)"
            @confirm="sendGiven"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <V2DateField
                v-model="givenOn"
                label="Given on"
                required
                :max="letters.today"
                :error="errors.given_on"
            />
        </V2Dialog>

        <!-- New or edited template -->
        <V2Dialog
            :open="tpl.open"
            :title="tpl.id ? 'Edit template' : 'New template'"
            description="Each save is a new version. Letters already issued keep their own text."
            size="tall"
            :confirm-label="tpl.processing ? 'Saving…' : 'Save template'"
            :busy="tpl.processing"
            :confirm-disabled="tplMissing !== ''"
            :confirm-disabled-reason="tplMissing"
            data-test="template-sheet"
            @update:open="(open: boolean) => (tpl.open = open)"
            @confirm="saveTemplate"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
            <div v-if="!tpl.id" class="grid gap-1.5">
                <label id="v2-tpl-type-label" class="text-sm font-medium"
                    >Kind of letter <span aria-hidden="true">*</span></label
                >
                <OperationalSelect
                    :model-value="tpl.type"
                    :options="option(letters.types)"
                    label="Kind of letter"
                    labelledby="v2-tpl-type-label"
                    placeholder="Choose a kind"
                    trigger-class="max-lg:h-11"
                    @update:model-value="(v) => (tpl.type = String(v))"
                />
                <p
                    v-if="errors.type"
                    role="alert"
                    class="text-sm text-destructive"
                >
                    {{ errors.type }}
                </p>
            </div>
            <V2Field
                v-slot="{ id, describedBy }"
                label="English title"
                required
                :error="errors.title"
            >
                <input
                    :id="id"
                    v-model="tpl.title"
                    maxlength="80"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Malay title"
                required
                hint="A Malay letter prints the Malay title."
                :error="errors.title_bm"
            >
                <input
                    :id="id"
                    v-model="tpl.titleBm"
                    maxlength="80"
                    :class="inputClass"
                    :aria-describedby="describedBy"
                />
            </V2Field>
            <p class="text-xs text-muted-foreground">
                Fields KPOne fills: {{ fields }}.
                <template v-if="detailsAllowed"
                    >{details} is the paragraph you write for each
                    letter.</template
                ><template v-else
                    >{details} is only for warning and show-cause
                    letters.</template
                >
            </p>
            <V2Field
                v-slot="{ id, describedBy }"
                label="English text"
                required
                :error="errors.body_en"
            >
                <textarea
                    :id="id"
                    v-model="tpl.bodyEn"
                    rows="10"
                    maxlength="5000"
                    :class="textareaClass"
                    :aria-describedby="describedBy"
                ></textarea>
            </V2Field>
            <V2Field
                v-slot="{ id, describedBy }"
                label="Malay text"
                required
                :error="errors.body_bm"
            >
                <textarea
                    :id="id"
                    v-model="tpl.bodyBm"
                    rows="10"
                    maxlength="5000"
                    :class="textareaClass"
                    :aria-describedby="describedBy"
                ></textarea>
            </V2Field>
        </V2Dialog>

        <!-- Retire a template -->
        <V2Dialog
            :open="retiring !== null"
            title="Retire this template?"
            :description="
                retiring
                    ? `${retiring.title}. It can no longer be used to issue letters. Letters already issued are kept.`
                    : ''
            "
            confirm-label="Retire"
            cancel-label="Keep template"
            tone="danger"
            :busy="retireBusy"
            @update:open="(open: boolean) => !open && (retiring = null)"
            @confirm="sendRetire"
        >
            <p v-if="failure" role="alert" class="text-sm text-destructive">
                {{ failure }}
            </p>
        </V2Dialog>
    </PageFrame>
</template>
