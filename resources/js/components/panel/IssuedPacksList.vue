<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * PNL-25: the frozen claim packs that include this claim, newest first: version, the label of its first print, how many
 * claims it holds, who issued it and when, and how many times it was printed. "View copy" opens the latest print;
 * "Reprint" records one more print of the same version, always a COPY. Both are outline buttons (the page keeps its own
 * primary action); each row is a card-less line so the list never nests a card inside a card.
 */
export type IssuedPackRow = {
    publicId: string;
    version: number;
    firstLabel: 'original' | 'revised' | 'copy';
    claimCount: number;
    issuedBy: string;
    issuedAt: string;
    prints: number;
    viewUrl: string;
    reprintUrl: string;
    /** False for a copy that prints identity numbers when this viewer's role cannot see them. */
    canOpen: boolean;
};

const props = defineProps<{ packs: IssuedPackRow[]; fromClaim: string }>();
const busy = ref<string | null>(null);
// One key per row and press, so a double click records one print and a retry of the same press never records two.
const keys = new Map<string, string>();
const keyFor = (id: string) => {
    if (!keys.has(id)) {
        keys.set(id, crypto.randomUUID());
    }

    return keys.get(id) as string;
};
const reprint = (pack: IssuedPackRow) => {
    if (busy.value !== null) {
        return;
    }

    busy.value = pack.publicId;
    router.post(
        pack.reprintUrl,
        { idempotency_key: keyFor(pack.publicId), from: props.fromClaim },
        {
            onFinish: () => {
                keys.delete(pack.publicId);
                busy.value = null;
            },
        },
    );
};
const labelWord = (label: string) =>
    label === 'original'
        ? 'Original'
        : label === 'revised'
          ? 'Revised'
          : 'Copy';
const buttonClass =
    'inline-flex min-h-11 items-center justify-center rounded-md border border-input bg-background px-4 text-sm font-medium whitespace-nowrap hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-progress disabled:opacity-60';
</script>

<template>
    <section
        v-if="packs.length > 0"
        class="grid gap-2 text-sm"
        aria-labelledby="issued-packs-heading"
        data-testid="issued-packs"
    >
        <h3 id="issued-packs-heading" class="font-medium">Issued packs</h3>
        <ul class="divide-y">
            <li
                v-for="pack in packs"
                :key="pack.publicId"
                class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-2"
            >
                <p class="min-w-0 tabular-nums">
                    <span class="font-medium"
                        >Version {{ pack.version }} ·
                        {{ labelWord(pack.firstLabel) }}</span
                    >
                    · {{ pack.claimCount }}
                    {{ pack.claimCount === 1 ? 'claim' : 'claims' }}
                    <span class="block text-muted-foreground"
                        >{{ pack.issuedAt }} by {{ pack.issuedBy }} ·
                        {{ pack.prints }}
                        {{ pack.prints === 1 ? 'print' : 'prints' }}</span
                    >
                </p>
                <p
                    v-if="!pack.canOpen"
                    class="text-xs text-muted-foreground"
                    data-testid="issued-pack-identity-note"
                >
                    This copy shows identity numbers. Your role cannot open it.
                </p>
                <div v-else class="flex flex-wrap gap-2">
                    <Link :href="pack.viewUrl" :class="buttonClass"
                        >View copy</Link
                    >
                    <button
                        type="button"
                        :class="buttonClass"
                        :disabled="busy !== null"
                        :aria-busy="busy === pack.publicId ? 'true' : undefined"
                        :aria-label="`Reprint version ${pack.version}`"
                        @click="reprint(pack)"
                    >
                        Reprint
                    </button>
                </div>
            </li>
        </ul>
    </section>
</template>
