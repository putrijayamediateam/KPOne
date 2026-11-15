<script setup lang="ts">
import type { ServiceRevision } from '@/types/website-services';
defineProps<{ revision: ServiceRevision; category: boolean }>();
const label = (key: string) => key.replaceAll('_', ' ');
</script>
<template>
    <section
        class="min-w-0 space-y-4 rounded-xl border p-5 [overflow-wrap:anywhere]"
        aria-label="Saved draft preview"
    >
        <h2 class="font-semibold">
            Saved draft preview · {{ revision.language.toUpperCase() }} ·
            revision {{ revision.number }}
        </h2>
        <p class="text-sm text-muted-foreground">
            Nothing is public yet. Exact saved draft; unsaved edits are not
            shown.
        </p>
        <img
            v-if="revision.content.cover_public_id"
            :src="`/website/media/${revision.content.cover_public_id}/image`"
            alt="Saved cover"
            class="max-h-64 max-w-full rounded object-contain"
        />
        <h3 class="text-xl font-semibold">
            {{ revision.content.name || 'Incomplete draft' }}
        </h3>
        <p>
            {{
                category
                    ? revision.content.short_description
                    : revision.content.short_summary
            }}
        </p>
        <template v-if="!category">
            <section
                v-for="(section, index) in revision.content
                    .description_sections"
                :key="index"
                class="space-y-2"
            >
                <h4 class="font-semibold">{{ section.heading }}</h4>
                <p>{{ section.body }}</p>
            </section>
            <div
                v-for="field in ['what_to_expect', 'suitable_for']"
                :key="field"
            >
                <h4 class="font-semibold capitalize">{{ label(field) }}</h4>
                <ul class="list-inside list-disc">
                    <li
                        v-for="(entry, index) in revision.content[
                            field
                        ] as string[]"
                        :key="index"
                    >
                        {{ entry }}
                    </li>
                </ul>
            </div>
            <p v-if="revision.content.price_text" class="rounded border p-3">
                {{ revision.content.price_text
                }}<span class="mt-1 block text-xs"
                    >Manual price text · approval reference:
                    {{ revision.content.price_display_reference }}</span
                >
            </p>
            <div class="grid min-w-0 grid-cols-2 gap-2">
                <figure
                    v-for="(id, index) in revision.content.gallery_public_ids"
                    :key="id"
                >
                    <img
                        :src="`/website/media/${id}/image`"
                        :alt="`Saved gallery image ${index + 1}`"
                        class="h-28 w-full rounded object-contain"
                    />
                    <figcaption class="text-xs">
                        Gallery {{ index + 1 }}
                    </figcaption>
                </figure>
            </div>
            <p class="text-sm">
                SEO title: {{ revision.content.seo_title || '—' }}
            </p>
            <p class="text-sm">
                SEO description: {{ revision.content.seo_description || '—' }}
            </p>
        </template>
        <dl class="space-y-2 text-xs text-muted-foreground">
            <div>
                <dt>Saved parent reference</dt>
                <dd>{{ revision.content.parent_public_id || 'None' }}</dd>
            </div>
            <div v-if="!category">
                <dt>Saved branch ids</dt>
                <dd>{{ revision.branchIds.join(', ') || 'None' }}</dd>
            </div>
            <div>
                <dt>Sort order</dt>
                <dd>{{ revision.content.sort_order }}</dd>
            </div>
            <div>
                <dt>Include on website</dt>
                <dd>
                    {{
                        revision.content.include_on_website
                            ? 'Yes · draft intent only'
                            : 'No'
                    }}
                </dd>
            </div>
        </dl>
    </section>
</template>
