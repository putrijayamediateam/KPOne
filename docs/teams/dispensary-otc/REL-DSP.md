# REL-DSP: the drug label size is confirmed (release note, for the owner's approval)

**Owner decision, 25 Nov 2026,** in the coordinator session, relayed by B.E.R.N.I.S.S. In the owner's words, the
real printer and sticker test at 100 × 62 mm and the pharmacist review "dah dpt approval". The approval was verbal.

This note turns the DSP-10 label size from provisional into confirmed, and records the pharmacist review as:
**owner-confirmed, verbal, 25 Nov 2026.** No names are recorded. Nothing is built until the owner approves this note.

## 1. What changes

**No behaviour changes.**
- The label stays exactly as DSP-10 built it: 100 × 62 mm, Courier New 9 pt, 48 characters × at most 13 rows. The
  size limits and blocking are unchanged.
- The only change on screen is one sentence on the label preview. The rest is a code comment and documents.

| Where | Today | After REL-DSP |
|---|---|---|
| Label preview, `resources/js/pages/Dispensary/Labels.vue` (the CA's line above the Print button) | "Label size 100 × 62 mm (provisional). Confirm printer and sticker stock. Print at 100%, browser headers/footers off." | "Label size 100 × 62 mm. Print at 100%, browser headers/footers off." |
| `app/Domain/Clinical/Dispensary/Labels/DrugLabelLayout.php` (class comment) | "100 × 62 mm provisional until the real printer test" | "100 × 62 mm, confirmed by the owner on 25 Nov 2026 after the printer test (REL-DSP)" |
| `docs/pilot/drug-label/printer-test.md` | Page size row ends "(provisional)" | The row drops "(provisional)". A short **Result** line at the top: "Done. Owner-confirmed, verbal, 25 Nov 2026: 100 × 62 mm on the real printer and sticker stock." |
| `docs/pilot/drug-label/pharmacist-review.md` | Size line "(provisional until the real printer test)"; empty sign-off box | The size line drops the bracket. A **Result** line at the top: "Owner-confirmed, verbal, 25 Nov 2026. No signed sheet is held in KPOne." The sign-off box stays as a blank form, for a signed copy the clinic may keep on paper. |
| `docs/pilot/drug-label/README.md` | "Two checks stand between the KPOne drug label and the pilot." | A status line: "Both checks done: owner-confirmed, verbal, 25 Nov 2026 (REL-DSP)." The rest stays as a record of what was checked. |
| `docs/guides/ms/ca.md`, line 112 (Malay CA guide) | "… masih bersifat sementara ("provisional"). Pencetak sebenar dan stok pelekat sebenar masih perlu diuji …: [PERLU DISAHKAN]." | "Label melintang ini bersaiz 100 × 62 mm. Saiz ini telah disahkan selepas ujian cetakan pada pencetak dan stok pelekat sebenar." |
| `docs/guides/ms/ca.md`, line 106 | "Ubat Terkawal/Controlled Medicine" "masih perlu disemak oleh ahli farmasi: [PERLU DISAHKAN]" | The pharmacist sentence and the marker are removed (the wording is part of the label review). |
| `docs/teams/dispensary-otc/TEAM.md` | Open items "Labels (DSP-10): 100 × 62 mm is provisional …" and "Pharmacist review: …" | Both are marked done (REL-DSP). Decision 42 records this approval. History rows are kept as written, because they record what was true at the time. |

The DSP-26 history row in TEAM.md also gets its PR number (#1086, merged as `477a252`), as B.E.R.N.I.S.S asked.

## 2. What the pharmacist review covers

- The review sheet (`pharmacist-review.md`) asks 13 questions about the drug label: its wording, the length limits,
  blocking a label that does not fit, the "Ubat Terkawal/Controlled Medicine" marker, "QTY: n", the REPRINT box,
  and discarding old labels.
- I read "dah dpt approval" as **accept as is**: the label stays as built, and no question asks for a change. If the
  pharmacist asked for any change, tell me and it becomes its own slice.

**Not covered by this note** (they mention a pharmacist too, but they are not the drug label):
- The Inventory team's controlled-drug register (STK-24), still marked "DRAFT pending pharmacist review" on its
  screens. That is Zulkifli's area and its own approval.
- The CA Supervisor guide's line that the drug group and controlled medicine lists need a pharmacist's check
  (`docs/guides/ms/penyelia-ca.md`, line 136). Those lists are catalogue data the clinic enters, not label wording.
  Tell me if the owner's approval covers them too, and I'll remove that line in this slice.

## 3. Existing assertions

**None change.**
- No test pins the word "provisional" or "Confirm printer and sticker stock".
- `tests/Frontend/dsp-26-doctor-view.test.mjs:103` pins `<p v-if="canPrint"> Label size`, and the new sentence keeps
  that start.
- `tests/Feature/Clinical/DrugLabelLandscapeTest.php` has a test named
  `test_the_preview_page_shows_the_provisional_landscape_size`. Its assertions pin the size (100 × 62), which stays.
  I'd rename the method to `test_the_preview_page_shows_the_landscape_size`, a name change only.

## 4. How it ships

- One small PR: the sentence on the preview, the code comment and the documents.
- Hakim reviews. Daniel's look: this is one sentence on an existing line, so no new screenshots unless he asks.
- Full gate as usual, then the owner's ship-it.

## 5. For the owner

1. Approve this note as written?
2. Is the pharmacist review "accept as is" (no change to the label)?
3. Does the approval also cover the drug group and controlled medicine lists line in the CA Supervisor guide?
