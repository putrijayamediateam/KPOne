<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import type {
    PeopleContent,
    PeoplePage,
    PeopleRevision,
} from '@/types/website-people';
const props = defineProps<{ people: PeoplePage }>();
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Website drafts', href: '/clinic-management' }],
    },
});
const doctor = computed(() => props.people.type === 'doctor_profile');
const title = computed(() => (doctor.value ? 'Doctor profiles' : 'Careers'));
const base = computed(() =>
    doctor.value ? '/website/doctor-profiles' : '/website/careers',
);
const language = ref<'en' | 'bm'>('en');
const blocked = ref(false);
const selected = ref<PeopleRevision | null>(null);
const values = () => ({
    expected_branch_id: props.people.branchId,
    lock_version: props.people.item?.lockVersion ?? 0,
    idempotency_key: crypto.randomUUID(),
    language: language.value,
    content: JSON.parse(
        JSON.stringify(props.people.drafts[language.value].content),
    ) as PeopleContent,
    branch_ids: [...(props.people.item?.branchIds ?? [props.people.branchId])],
    portrait_public_id: props.people.drafts[language.value].portraitPublicId,
});
const form = useForm(values());
const filters = useForm({
    search: props.people.filters.search ?? '',
    branch_id: props.people.filters.branch_id ?? '',
    archived: !!props.people.filters.archived,
});
const adopt = () => {
    form.defaults(values());
    form.reset();
    form.clearErrors();
    selected.value = null;
    blocked.value = false;
};
const changeLanguage = (value: 'en' | 'bm') => {
    if (!form.isDirty && !form.processing) {
        language.value = value;
        adopt();
    }
};
const save = () => {
    if (
        !blocked.value &&
        props.people.canManage &&
        !props.people.item?.archived
    ) {
        form.post(
            props.people.item
                ? `${base.value}/${props.people.item.publicId}`
                : base.value,
            {
                preserveScroll: true,
                onSuccess: async () => {
                    await nextTick();
                    adopt();
                },
            },
        );
    }
};
const archive = () => {
    if (props.people.item && props.people.canManage && !blocked.value) {
        form.post(
            `${base.value}/${props.people.item.publicId}/${props.people.item.archived ? 'unarchive' : 'archive'}`,
            {
                onSuccess: async () => {
                    await nextTick();
                    adopt();
                },
            },
        );
    }
};
const applyFilters = () => filters.get(base.value, { preserveState: false });
const preview = computed(
    () => selected.value ?? props.people.drafts[language.value],
);
const list = (key: string) => form.content[key] as string[];
const label = (key: string) =>
    key.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
const stop = router.on('start', (event) => {
    if (event.detail.visit.url.pathname === '/branch-context') {
        blocked.value = true;
        selected.value = null;
        form.content = {};
        form.branch_ids = [];
        form.portrait_public_id = null;
        form.idempotency_key = crypto.randomUUID();
        filters.reset();
    }
});
watch(
    () => [
        props.people.branchId,
        props.people.item?.publicId,
        props.people.item?.lockVersion,
    ],
    adopt,
);
onUnmounted(stop);
const inputClass =
    'mt-1 w-full min-w-0 rounded-md border bg-background px-3 py-2 text-sm';
</script>
<template>
    <Head :title="title" />
    <main
        class="mx-auto w-full max-w-7xl min-w-0 space-y-5 p-4 [overflow-wrap:anywhere] sm:p-6"
    >
        <Link href="/clinic-management" class="text-sm underline"
            >Clinic management</Link
        >
        <h1 class="text-2xl font-semibold">{{ title }}</h1>
        <p
            class="rounded-lg border border-amber-400/60 bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950/30 dark:text-amber-100"
        >
            Nothing is public yet. Private draft intent only; this screen cannot
            publish. Do not include patient data or private staff contact
            details.
            {{
                doctor
                    ? 'Manual entries only. Registration text and consent references are entered by a human and are not verified or filled by KPOne.'
                    : 'Content only. No applicant records or application form.'
            }}
        </p>
        <p v-if="blocked" role="status">
            Branch changed. Loaded content and drafts cleared. Reload to
            continue.
        </p>
        <template v-else>
            <template v-if="!people.editing">
                <form
                    class="flex min-w-0 flex-wrap items-end gap-3 rounded-xl border p-4"
                    @submit.prevent="applyFilters"
                >
                    <label class="min-w-0 flex-1 text-sm"
                        >Search<input
                            v-model="filters.search"
                            maxlength="150"
                            :class="inputClass"
                    /></label>
                    <label class="min-w-0 text-sm"
                        >Branch<select
                            v-model="filters.branch_id"
                            :class="inputClass"
                        >
                            <option value="">All organisation branches</option>
                            <option
                                v-for="branch in people.branches"
                                :key="branch.id"
                                :value="branch.id"
                            >
                                {{ branch.name }}
                            </option>
                        </select></label
                    >
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="filters.archived"
                            type="checkbox"
                        />Show archived</label
                    >
                    <Button type="submit">Apply filters</Button>
                </form>
                <Link
                    v-if="people.canManage"
                    :href="`${base}/create`"
                    class="inline-block rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground"
                    >Create
                    {{ doctor ? 'doctor profile' : 'career posting' }}</Link
                >
                <p v-if="!people.items.length" class="rounded-xl border p-6">
                    No drafts yet.
                    {{
                        doctor
                            ? 'Profiles are entered manually; staff and credentials are never imported.'
                            : 'Create a private content draft to begin.'
                    }}
                </p>
                <ul class="space-y-3">
                    <li
                        v-for="item in people.items"
                        :key="item.publicId"
                        class="rounded-xl border p-4"
                    >
                        <Link
                            :href="`${base}/${item.publicId}`"
                            class="font-semibold underline"
                            >{{ item.title || 'Incomplete draft' }}</Link
                        >
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ item.archived ? 'Archived' : 'Private draft' }} ·
                            version {{ item.lockVersion }}
                        </p>
                    </li>
                </ul>
                <nav
                    v-if="people.pages > 1"
                    class="flex flex-wrap gap-3 text-sm"
                >
                    <Link
                        v-if="people.page > 1"
                        :href="base"
                        :data="{ ...filters.data(), page: people.page - 1 }"
                        >Previous</Link
                    ><span>{{ people.page }} / {{ people.pages }}</span
                    ><Link
                        v-if="people.page < people.pages"
                        :href="base"
                        :data="{ ...filters.data(), page: people.page + 1 }"
                        >Next</Link
                    >
                </nav>
            </template>
            <template v-else>
                <Link :href="base" class="text-sm underline">Back to list</Link>
                <p v-if="!people.canManage" class="rounded border p-3">
                    View only. No save or archive controls.
                </p>
                <p v-if="people.item?.archived" class="rounded border p-3">
                    Archived. Revisions are retained. Unarchive before editing.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="lang in ['en', 'bm'] as const"
                        :key="lang"
                        :variant="language === lang ? 'default' : 'outline'"
                        :disabled="form.isDirty || form.processing"
                        @click="changeLanguage(lang)"
                        >{{
                            lang === 'en'
                                ? 'English (EN)'
                                : 'Bahasa Melayu (BM)'
                        }}</Button
                    >
                </div>
                <p role="status">
                    {{
                        people.drafts[language].missing.length
                            ? `Incomplete · missing ${people.drafts[language].missing.map(label).join(', ')}`
                            : doctor
                              ? 'Complete · required content and consent reference entered'
                              : 'Complete · required content entered'
                    }}
                    · revision {{ people.drafts[language].number }}
                </p>
                <p v-if="form.isDirty" class="text-sm text-muted-foreground">
                    Save or reload before changing language. Preview shows the
                    saved revision.
                </p>
                <div
                    v-if="Object.keys(form.errors).length"
                    role="alert"
                    class="rounded border border-destructive p-3 text-destructive"
                >
                    <p v-for="(error, key) in form.errors" :key="key">
                        {{ error }}
                    </p>
                </div>
                <div class="grid min-w-0 gap-6 xl:grid-cols-2">
                    <form
                        class="min-w-0 space-y-4 rounded-xl border p-5"
                        @submit.prevent="save"
                    >
                        <fieldset
                            :disabled="
                                !people.canManage ||
                                form.processing ||
                                people.item?.archived
                            "
                            class="min-w-0 space-y-4"
                        >
                            <legend class="mb-3 font-semibold">
                                {{ language.toUpperCase() }} draft fields
                            </legend>
                            <label
                                v-for="(limit, key) in people.textLimits"
                                :key="key"
                                class="block text-sm"
                                >{{ label(key) }} (max {{ limit }})<textarea
                                    v-if="
                                        key === 'biography' || key === 'summary'
                                    "
                                    :name="key"
                                    :value="String(form.content[key] ?? '')"
                                    :maxlength="limit"
                                    rows="5"
                                    :class="inputClass"
                                    @input="
                                        form.content[key] = (
                                            $event.target as HTMLTextAreaElement
                                        ).value
                                    " /><input
                                    v-else
                                    :name="key"
                                    :value="String(form.content[key] ?? '')"
                                    :maxlength="limit"
                                    :class="inputClass"
                                    @input="
                                        form.content[key] = (
                                            $event.target as HTMLInputElement
                                        ).value
                                    "
                            /></label>
                            <p class="text-xs text-muted-foreground">
                                Plain text only; no markup, line breaks or
                                hidden characters.
                                {{
                                    doctor
                                        ? 'Public registration text is optional; displaying it requires an approval reference. A profile consent reference is required for completeness.'
                                        : 'Provide exactly one HTTPS application link or public HR email. Closing date is a branch-local date, without a time.'
                                }}
                            </p>
                            <fieldset
                                v-for="(
                                    [count, limit], key
                                ) in people.listLimits"
                                :key="key"
                                class="min-w-0 space-y-2"
                            >
                                <legend>
                                    {{ label(key) }} (up to {{ count }}, max
                                    {{ limit }} each)
                                </legend>
                                <div
                                    v-for="(_, index) in list(key)"
                                    :key="index"
                                    class="flex min-w-0 gap-2"
                                >
                                    <input
                                        v-model="list(key)[index]"
                                        :aria-label="`${label(key)} ${index + 1}`"
                                        :maxlength="limit"
                                        :class="inputClass"
                                    /><Button
                                        type="button"
                                        variant="outline"
                                        @click="list(key).splice(index, 1)"
                                        >Remove</Button
                                    >
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="list(key).length >= count"
                                    @click="list(key).push('')"
                                    >Add
                                    {{ label(key).toLowerCase() }} entry</Button
                                >
                            </fieldset>
                            <template v-if="!doctor"
                                ><label class="block text-sm"
                                    >Employment type<select
                                        v-model="form.content.employment_type"
                                        :class="inputClass"
                                    >
                                        <option
                                            v-for="type in people.employmentTypes"
                                            :key="type"
                                            :value="type"
                                        >
                                            {{ label(type) }}
                                        </option>
                                    </select></label
                                ><label class="block text-sm"
                                    >Closing date (optional)<input
                                        v-model="form.content.closing_date"
                                        type="date"
                                        :class="inputClass" /></label
                            ></template>
                            <fieldset class="space-y-2">
                                <legend>
                                    Associated branches (one or more)
                                </legend>
                                <label
                                    v-for="branch in people.branches"
                                    :key="branch.id"
                                    class="flex items-center gap-2 text-sm"
                                    ><input
                                        v-model="form.branch_ids"
                                        type="checkbox"
                                        :value="branch.id"
                                    />{{ branch.name }}</label
                                >
                            </fieldset>
                            <label v-if="doctor" class="block text-sm"
                                >Portrait (optional, consent required on
                                asset)<select
                                    v-model="form.portrait_public_id"
                                    :class="inputClass"
                                >
                                    <option :value="null">No portrait</option>
                                    <option
                                        v-for="asset in people.assets"
                                        :key="asset.public_id"
                                        :value="asset.public_id"
                                    >
                                        {{
                                            language === 'en'
                                                ? asset.alt_en
                                                : asset.alt_bm
                                        }}
                                    </option></select
                                ><Link
                                    href="/website/media"
                                    class="mt-2 inline-block underline"
                                    >Open media library</Link
                                ></label
                            >
                            <label class="block text-sm"
                                >Sort order (-100000 to 100000)<input
                                    v-model.number="form.content.sort_order"
                                    type="number"
                                    min="-100000"
                                    max="100000"
                                    :class="inputClass"
                            /></label>
                            <label class="flex items-center gap-2 text-sm"
                                ><input
                                    v-if="doctor"
                                    v-model="form.content.include_on_website"
                                    type="checkbox"
                                /><input
                                    v-else
                                    v-model="form.content.open"
                                    type="checkbox"
                                />{{ doctor ? 'Include on website' : 'Open' }} ·
                                draft intent only</label
                            >
                            <Button
                                v-if="people.canManage"
                                type="submit"
                                :disabled="form.processing"
                                >Save {{ language.toUpperCase() }} draft</Button
                            >
                        </fieldset>
                    </form>
                    <div class="min-w-0 space-y-5">
                        <section
                            class="min-w-0 space-y-3 rounded-xl border p-5"
                            aria-label="Saved draft preview"
                        >
                            <h2 class="font-semibold">
                                Saved draft preview ·
                                {{ preview.language.toUpperCase() }} · revision
                                {{ preview.number }}
                            </h2>
                            <p class="text-sm text-muted-foreground">
                                Nothing is public yet. This exact saved draft is
                                visible only inside KPOne.
                            </p>
                            <img
                                v-if="preview.portraitPublicId"
                                :src="`/website/media/${preview.portraitPublicId}/image`"
                                alt="Saved portrait"
                                class="max-h-64 max-w-full rounded object-contain"
                            />
                            <dl class="space-y-3">
                                <div
                                    v-for="(value, key) in preview.content"
                                    :key="key"
                                >
                                    <dt
                                        class="text-xs font-semibold text-muted-foreground"
                                    >
                                        {{ label(key) }}
                                    </dt>
                                    <dd v-if="Array.isArray(value)">
                                        <ul class="list-inside list-disc">
                                            <li
                                                v-for="(entry, index) in value"
                                                :key="index"
                                            >
                                                {{ entry || '—' }}
                                            </li>
                                        </ul>
                                    </dd>
                                    <dd v-else>
                                        {{
                                            typeof value === 'boolean'
                                                ? value
                                                    ? 'Yes · draft intent'
                                                    : 'No'
                                                : value === ''
                                                  ? '—'
                                                  : value
                                        }}
                                    </dd>
                                </div>
                            </dl>
                            <p v-if="preview.branchIds?.length" class="text-sm">
                                Saved branch associations:
                                {{
                                    preview.branchIds
                                        .map(
                                            (id) =>
                                                people.branches.find(
                                                    (b) => b.id === id,
                                                )?.name ?? 'Unavailable branch',
                                        )
                                        .join(', ')
                                }}
                            </p>
                        </section>
                        <section class="space-y-3 rounded-xl border p-5">
                            <h2 class="font-semibold">
                                Revision history · read only
                            </h2>
                            <button
                                v-for="revision in people.history"
                                :key="revision.id ?? 0"
                                class="block w-full rounded border p-3 text-left text-sm"
                                @click="selected = revision"
                            >
                                {{ revision.language.toUpperCase() }} · revision
                                {{ revision.number }} ·
                                {{ revision.createdAt }} UTC</button
                            ><Button
                                v-if="selected"
                                variant="outline"
                                @click="selected = null"
                                >Show current saved draft</Button
                            >
                            <nav
                                v-if="people.historyPages > 1"
                                class="flex flex-wrap gap-3 text-sm"
                            >
                                <Link
                                    v-if="people.historyPage > 1"
                                    :href="`${base}/${people.item?.publicId}`"
                                    :data="{
                                        history_page: people.historyPage - 1,
                                    }"
                                    >Previous revisions</Link
                                ><span
                                    >{{ people.historyPage }} /
                                    {{ people.historyPages }}</span
                                ><Link
                                    v-if="
                                        people.historyPage < people.historyPages
                                    "
                                    :href="`${base}/${people.item?.publicId}`"
                                    :data="{
                                        history_page: people.historyPage + 1,
                                    }"
                                    >Next revisions</Link
                                >
                            </nav>
                        </section>
                        <section
                            v-if="people.item"
                            class="space-y-3 rounded-xl border p-5"
                        >
                            <p class="text-xs text-muted-foreground">
                                Stable internal slug (not a public URL):
                                {{ people.item.slug }}
                            </p>
                            <Button
                                v-if="people.canManage"
                                variant="outline"
                                :disabled="form.processing || form.isDirty"
                                @click="archive"
                                >{{
                                    people.item.archived
                                        ? 'Unarchive'
                                        : 'Archive'
                                }}</Button
                            >
                            <p class="text-xs text-muted-foreground">
                                Archive never deletes revisions. Save or reload
                                changes before archiving.
                            </p>
                        </section>
                    </div>
                </div>
            </template>
        </template>
    </main>
</template>
