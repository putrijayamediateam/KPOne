<script setup lang="ts">
import type { EditorialRevision } from '@/types/website-editorial';
defineProps<{
    revision: EditorialRevision;
    type: 'promotion' | 'activity' | 'faq';
}>();
const label = (key: string) =>
    key.replaceAll('_', ' ').replace(/^./, (c) => c.toUpperCase());
</script>
<template>
    <section
        aria-label="Saved draft preview"
        class="min-w-0 space-y-4 rounded-xl border p-5 [overflow-wrap:anywhere]"
    >
        <h2 class="font-semibold">
            Saved draft preview · {{ revision.language.toUpperCase() }} ·
            revision {{ revision.number }}
        </h2>
        <p class="text-sm text-muted-foreground">
            Nothing is public yet. Exact saved revision; unsaved edits are not
            shown. Dates and offers trigger nothing.
        </p>
        <img
            v-if="revision.content.cover_public_id"
            :src="`/website/media/${revision.content.cover_public_id}/image`"
            alt="Saved cover"
            class="max-h-64 max-w-full rounded object-contain"
        />
        <h3 class="text-lg font-semibold">
            {{ revision.content.title ?? revision.content.question }}
        </h3>
        <p>{{ revision.content.short_summary ?? revision.content.answer }}</p>
        <div
            v-for="(section, index) in revision.content.description_sections"
            :key="index"
            class="space-y-2"
        >
            <h4 class="font-semibold">{{ section.heading }}</h4>
            <p>{{ section.body }}</p>
        </div>
        <ul
            v-if="revision.content.terms?.length"
            class="list-disc space-y-2 pl-5"
        >
            <li v-for="(term, index) in revision.content.terms" :key="index">
                {{ term }}
            </li>
        </ul>
        <p v-if="revision.content.offer_text">
            {{ revision.content.offer_text
            }}<span class="block text-xs text-muted-foreground"
                >Manual offer · display-approval reference:
                {{ revision.content.offer_display_reference }}</span
            >
        </p>
        <template
            v-for="key in [
                'valid_from',
                'valid_until',
                'starts_on',
                'ends_on',
                'location_note',
            ]"
            :key="key"
            ><p v-if="revision.content[key]">
                {{ label(key) }}: {{ revision.content[key] }}
            </p></template
        >
        <figure
            v-for="(id, index) in revision.content.gallery_public_ids"
            :key="id"
        >
            <img
                :src="`/website/media/${id}/image`"
                :alt="`Saved gallery image ${index + 1}`"
                class="max-h-48 max-w-full rounded object-contain"
            />
            <figcaption class="text-xs">Gallery {{ index + 1 }}</figcaption>
        </figure>
        <dl class="text-xs text-muted-foreground">
            <template v-if="type === 'faq'"
                ><dt>Saved service reference</dt>
                <dd>
                    {{ revision.content.linked_service_public_id ?? 'None' }}
                </dd></template
            ><template v-else
                ><dt>Saved branch ids</dt>
                <dd>{{ revision.branchIds.join(', ') || 'None' }}</dd></template
            >
            <dt>Sort order</dt>
            <dd>{{ revision.content.sort_order }}</dd>
            <dt>Include on website</dt>
            <dd>
                {{
                    revision.content.include_on_website
                        ? 'Yes · draft intent only'
                        : 'No'
                }}
            </dd>
        </dl>
    </section>
</template>
