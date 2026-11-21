# DSP-25: the Director's read-only view of a dispensary case (design note)

Status: **draft for Hakim's review.** Not built. Classic only, because V2 was retired on 19 Nov.

- Source: owner decision 4 of 20-21 Nov 2026 (`docs/decisions/2026-11-20-finance-inventory-proposals.md`): "Director
  gets a read-only view of consultations and dispensary; every view is audited." This is an explicit owner role
  decision, as `AGENTS.md` requires for clinical access.
- The new permission still waits for the owner's approval. B.E.R.N.I.S.S has asked him.
- Built together with Amirul (Queue & TV), who does the consultation side (`consultation.reviewed`). This PR goes
  first and carries the shared pieces.

## 1. What the Director sees

- On the visit page, a visit that has a dispensary case shows an **"Open dispensary case (read only)"** link, for
  the Director only.
- It opens `GET /dispensary/{case}/review`. The page has:
  - the shared banner, "Read only. Every view is recorded.", with Back;
  - the patient card: name, KP number and allergies as recorded;
  - the case status, the doctor's order beside what the CA dispensed, the batches and expiry, the service lines, and
    any doctor's check or returns.
- There are no buttons except Back. There is no label preview, print, WhatsApp, scan or edit.
- It works at any branch, because the Director is organisation-wide.

## 2. Shared rules, agreed with Amirul

1. **View only.** A new permission, `dispensary.view_readonly.organisation`, for `director` only. It never implies a
   write permission. A test asserts that the Director still holds none of the Dispensary write permissions.
2. **GET only.** The review route is GET. A source test fails if any POST, PATCH, PUT or DELETE route appears under
   `/dispensary/{case}/review`.
3. **Its own policy.** A new `DispensaryCasePolicy::review()` checks the permission and the organisation, fresh on
   every request. It never uses the CA's branch policy or the doctor's own-encounter rule. A case from another
   organisation returns 404.
4. **Audited before anything is shown.** `ReadOnlyReviewAudit::record()` writes `dispensary.case_reviewed` with ids
   only (case, visit, patient), never content. If the audit row cannot be written, it throws and the page shows
   nothing.
5. **One shared banner,** `resources/js/components/ReadOnlyBanner.vue`.
6. **The existing routes still refuse.** The Director holds no Dispensary write permission, so every existing POST
   returns 403. A test posts to each Dispensary write route as the Director and proves it.

## 3. Shared pieces (this PR; Amirul's PR uses them)

- `app/Domain/Shared/ReadOnlyReviewAudit.php`:
  - signature: `record(User $actor, string $event, Model $subject, array $ids): void`;
  - it goes through the existing `AuditRecorder`, inside the request transaction;
  - it throws if the row was not written;
  - the event names and ids keys are allowlisted, so no content can slip in.
- `resources/js/components/ReadOnlyBanner.vue`: the text above and a Back slot. It uses the theme tokens, readable
  in light and dark.

## 4. What the page is built from

- A new `DispensaryReviewService::project()`, which reuses the projection the Completed page uses. It drops every
  action URL, lock version, token and idempotency field, so the payload has nothing a form could post.
- A test pins the payload's keys.
- The page is a new `pages/Dispensary/Review.vue`. It shares the Completed page's read-only table partials rather
  than copying them.

## 5. Privacy

- It shows only what the Completed page already shows to dispensary staff. It adds no IC number, phone or address
  beyond the existing patient card, and no clinical notes or diagnosis.
- The response is `no-store`, with a throttle of 60 a minute.

## 6. Tests

- The Director:
  - opens a case at another branch (200);
  - the audit row is written first, with ids only;
  - a failed audit write shows nothing (500, no content in the body);
  - the payload keys are pinned, with no URLs or versions;
  - every write route returns 403;
  - a case from another organisation returns 404.
- Every other role gets 403 on the review route. That includes CA and CA Supervisor, who keep their normal pages.
- The source test for GET only, and the permission catalogue and the migration (additive, idempotent).
- **Existing assertions that will fail and are left failing for the owner:**
  - the Director's permission list in `tests/Frontend/fixtures/access-control-roles.json`;
  - the backend role-permission pins that list the Director's permissions exactly.
  - The final list will come with the build.

## 7. Question for the owner

- Approve `dispensary.view_readonly.organisation` for the Director only? (Recommended: yes. It is owner decision 4.)
