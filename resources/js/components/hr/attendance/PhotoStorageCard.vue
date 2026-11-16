<script setup lang="ts">
// HR-PHOTO-STORAGE in Classic (owner, 16 Nov 2026: "photo card in classic too"): the read-only "Clock-in photo storage"
// card on the Classic attendance Settings tab, in Classic's own style, with the same figures and words as the V2 card.
// Director and HR Manager only (the prop is sent only to them). Counts only: no names, photos or per-person figures, no
// Save, and nothing is written when it opens.
import { StatusBadge } from '@/components/ui/status';
import type { PhotoStorageCard } from './types';

defineProps<{ card: PhotoStorageCard }>();

const count = (n: number) => n.toLocaleString('en-GB');
const plural = (n: number, one: string, many: string) =>
    `${count(n)}\u00A0${n === 1 ? one : many}`;
// "182 MB" with a no-break space, so a figure never leaves its unit.
const glue = (size: string) => size.replace(' ', '\u00A0');
const word = (n: number) =>
    ['no', 'one', 'two', 'three', 'four', 'five', 'six'][n] ?? count(n);
</script>

<template>
    <section
        aria-labelledby="photo-storage-title"
        class="flex flex-col gap-3"
        data-test="photo-storage"
    >
        <h2 id="photo-storage-title" class="text-base font-semibold">
            Clock-in photo storage
        </h2>
        <div>
            <StatusBadge
                status="photo_retention"
                :tone="card.draft ? 'warning' : 'neutral'"
                :label="`Retention: ${card.retentionYears} years${card.draft ? ' · draft, to confirm' : ` · ${card.basis}`}`"
                data-test="photo-storage-retention"
            />
        </div>
        <p class="max-w-prose text-sm text-muted-foreground">
            Photos are kept with their attendance record, then deleted with it.
            <template v-if="card.earliestDelete">
                The earliest delete is
                <span class="whitespace-nowrap tabular-nums">{{
                    card.earliestDelete
                }}</span
                >.
            </template>
        </p>

        <p
            v-if="card.photos === 0"
            class="text-sm text-muted-foreground"
            data-test="photo-storage-empty"
        >
            No clock-in photos yet.
        </p>
        <template v-else>
            <dl
                class="grid max-w-xl grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-3"
                data-test="photo-storage-figures"
            >
                <div class="flex min-w-0 flex-col">
                    <dt class="text-xs text-muted-foreground">Stored</dt>
                    <dd class="font-medium whitespace-nowrap tabular-nums">
                        {{ plural(card.photos, 'photo', 'photos') }}
                    </dd>
                </div>
                <div class="flex min-w-0 flex-col">
                    <dt class="text-xs text-muted-foreground">Size</dt>
                    <dd class="font-medium whitespace-nowrap tabular-nums">
                        {{ glue(card.size) }}
                    </dd>
                </div>
                <div class="flex min-w-0 flex-col">
                    <dt class="text-xs text-muted-foreground">Oldest photo</dt>
                    <dd class="font-medium whitespace-nowrap tabular-nums">
                        {{ card.oldest }}
                    </dd>
                </div>
                <div class="flex min-w-0 flex-col">
                    <dt class="text-xs text-muted-foreground">This month</dt>
                    <dd class="font-medium whitespace-nowrap tabular-nums">
                        {{ plural(card.thisMonth, 'photo', 'photos') }}
                    </dd>
                </div>
                <div
                    v-if="card.perMonth !== null && card.perMonthSize"
                    class="flex min-w-0 flex-col"
                >
                    <dt class="text-xs text-muted-foreground">Per month</dt>
                    <dd class="font-medium whitespace-nowrap tabular-nums">
                        ~{{ plural(card.perMonth, 'photo', 'photos') }} · ~{{
                            glue(card.perMonthSize)
                        }}
                    </dd>
                </div>
            </dl>

            <p
                v-if="card.forecastOneYear && card.forecastRetention"
                class="max-w-prose text-sm"
                data-test="photo-storage-forecast"
            >
                <span class="font-medium"
                    >Rough forecast, from the last
                    {{ word(card.forecastMonths) }} full months:</span
                >{{ ' '
                }}<span class="whitespace-nowrap tabular-nums"
                    >~{{ glue(card.forecastOneYear) }}</span
                >
                after 1 year, and
                <span class="whitespace-nowrap tabular-nums"
                    >~{{ glue(card.forecastRetention) }}</span
                >
                at {{ card.retentionYears }} years, when deletes start.
            </p>
            <p
                v-else
                class="text-sm text-muted-foreground"
                data-test="photo-storage-no-forecast"
            >
                A forecast appears after
                {{ word(card.forecastMonths) }} full months.
            </p>
        </template>

        <p class="max-w-prose text-xs text-muted-foreground">
            Totals only. No names. Sizes are from the saved photos and
            thumbnails; the encrypted files on disk take about a third more.
        </p>
    </section>
</template>
