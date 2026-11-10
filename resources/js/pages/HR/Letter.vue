<script setup lang="ts">
// HR-10a letter (HR-PLAN 20.3): the frozen snapshot of an issued letter, A4, white in every theme (PRINT-1). Opening it is
// recorded. Printed and signed by hand; the clinic's adviser approves the wording before real use.
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    letter: {
        id: string;
        own: boolean;
        reference: string;
        type: string;
        language: 'en' | 'bm';
        title: string;
        recipient: string;
        date: string;
        body: string;
        replaces: string | null;
        status: 'current' | 'replaced' | 'void';
        letterhead: {
            clinicName: string;
            address: string;
            contact: string;
            logo: {
                src: string;
                width: number | null;
                height: number | null;
            } | null;
        };
    };
}>();

const bm = computed(() => props.letter.language === 'bm');
const words = computed(() =>
    bm.value
        ? {
              reference: 'Rujukan',
              date: 'Tarikh',
              replaces: 'Menggantikan',
              signature: 'Tandatangan dan cop klinik',
              closing: 'Yang benar,',
              received: 'Diterima oleh',
              receivedDate: 'Tarikh',
          }
        : {
              reference: 'Reference',
              date: 'Date',
              replaces: 'Replaces',
              signature: 'Signature and clinic stamp',
              closing: 'Yours sincerely,',
              received: 'Received by',
              receivedDate: 'Date',
          },
);
const paragraphs = computed(() =>
    props.letter.body
        .split(/\n\s*\n/)
        .map((p) => p.trim())
        .filter((p) => p !== ''),
);
// A closing line before the signature, unless the template already ends with one (Daniel).
const hasClosing = computed(() =>
    /(sincerely|faithfully|regards|yang benar|sekian|terima kasih)[,.]?\s*$/i.test(
        paragraphs.value[paragraphs.value.length - 1] ?? '',
    ),
);
const banner = computed(() =>
    props.letter.status === 'replaced'
        ? 'REPLACED: this letter was replaced by a newer letter. Do not use.'
        : props.letter.status === 'void'
          ? 'VOID: this letter was voided. Do not use.'
          : null,
);
const print = () => window.print();
</script>

<template>
    <Head :title="`${letter.title} · ${letter.reference}`" />
    <!-- On screen the letter is a centred sheet on the theme background, which fills the viewport (Daniel). -->
    <div
        class="hr-letter-page min-h-screen bg-muted/40 px-4 py-4 sm:px-8 sm:py-8 print:min-h-0 print:bg-white print:p-0"
    >
        <main
            class="document-print hr-letter mx-auto w-full max-w-[210mm] min-w-0 rounded-md border bg-background p-4 text-sm text-foreground sm:p-10 print:rounded-none print:border-0 print:bg-white print:text-black"
            data-test="hr-letter"
        >
            <div
                class="mb-4 flex flex-wrap items-center gap-3 print:hidden"
                data-testid="print-toolbar"
            >
                <button
                    type="button"
                    class="min-h-11 rounded-md border px-3 py-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9"
                    @click="print"
                >
                    Print letter
                </button>
                <a
                    :href="
                        letter.own
                            ? '/hr/letters?tab=mine'
                            : '/hr/letters?tab=issued'
                    "
                    class="inline-flex min-h-11 items-center rounded-md px-3 py-2 underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none lg:min-h-9"
                    >Back to letters</a
                >
            </div>

            <p
                v-if="banner"
                class="mb-4 rounded-md border-2 border-current p-2 text-center font-semibold"
                data-test="letter-status-banner"
            >
                {{ banner }}
            </p>

            <!-- The same letterhead as the other letters: logo if set, the clinic name in bold, the address on one line, then
             "Tel: …" printed through PrintedPhone on the server, all frozen when the letter was issued. -->
            <header
                class="mb-6 flex items-start gap-4 border-b pb-4"
                data-test="letter-letterhead"
            >
                <img
                    v-if="letter.letterhead.logo"
                    :src="letter.letterhead.logo.src"
                    alt=""
                    class="h-14 w-auto max-w-32 object-contain"
                />
                <div class="min-w-0">
                    <p class="text-base font-bold">
                        {{ letter.letterhead.clinicName }}
                    </p>
                    <p v-if="letter.letterhead.address" class="text-xs">
                        {{ letter.letterhead.address }}
                    </p>
                    <p v-if="letter.letterhead.contact" class="text-xs">
                        {{ letter.letterhead.contact }}
                    </p>
                </div>
            </header>

            <section class="mb-6 flex flex-wrap justify-between gap-3">
                <p class="font-semibold break-words">{{ letter.recipient }}</p>
                <div class="text-right text-xs">
                    <p>
                        {{ words.reference }}:
                        <span class="font-semibold tabular-nums">{{
                            letter.reference
                        }}</span>
                    </p>
                    <p>{{ words.date }}: {{ letter.date }}</p>
                    <p v-if="letter.replaces">
                        {{ words.replaces }}: {{ letter.replaces }}
                    </p>
                </div>
            </section>

            <h1 class="mb-4 text-base font-bold text-balance underline">
                {{ letter.title }}
            </h1>
            <div
                class="flex flex-col gap-3 leading-relaxed"
                data-test="letter-body"
            >
                <p
                    v-for="(paragraph, i) in paragraphs"
                    :key="i"
                    class="break-words whitespace-pre-line"
                >
                    {{ paragraph }}
                </p>
            </div>

            <p v-if="!hasClosing" class="mt-6" data-test="letter-closing">
                {{ words.closing }}
            </p>

            <!-- At least 30 mm above each line for a signature and the clinic stamp (Daniel). -->
            <section class="mt-2 grid gap-10 sm:grid-cols-2 print:grid-cols-2">
                <div>
                    <div class="h-[30mm] border-b border-current"></div>
                    <p class="mt-1 text-xs">{{ words.signature }}</p>
                </div>
                <div>
                    <div class="h-[30mm] border-b border-current"></div>
                    <p class="mt-1 text-xs">
                        {{ words.received }}: {{ letter.recipient }} ·
                        {{ words.receivedDate }}:
                    </p>
                </div>
            </section>
        </main>
    </div>
</template>

<style>
/* A real A4 page margin of 15 mm on every printed page (Daniel). */
@page {
    size: A4;
    margin: 15mm;
}
@media print {
    .hr-letter {
        max-width: none;
        padding: 0;
        background: white;
        color: black;
    }
    .hr-letter p {
        overflow-wrap: anywhere;
    }
}
</style>
