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

### 1a. Daniel's notes on the design (OK with changes, 21 Nov)

1. **The banner** uses the neutral or info tokens, not warning, and sits at the top, above the patient card. Back is
   the only control.
2. **Nothing is shown disabled.** The page has no buttons, inputs or sticky action bars, so it never looks editable.
3. **Statuses** show as a dot and a word. At 390 px, ordered beside dispensed uses the same card layout as the
   Completed page.
4. **The entry link** "Open dispensary case (read only)" reads as an outline link. Every page that opens the review
   uses the same label.

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
6. **The existing routes still refuse.** A test posts to every Dispensary write route as the Director and checks two
   things: the request is refused, and nothing is written. Permissions alone don't cover everything (Hakim):
   - The Director holds `inventory.variances.approve.branch`, the only middleware on
     `POST dispensary/{case}/returns/{id}/check`. That route is refused by the service's own checks (the CA
     Supervisor role and `dispensary.view.branch`), so its response may not be the middleware's 403.
   - The Director holds `queue.call.branch`. The review page offers no call button and has no call URL in its data.

## 3. Shared pieces (this PR; Amirul's PR uses them)

- `app/Domain/Shared/ReadOnlyReviewAudit.php`:
  - signature: `record(User $actor, string $event, Model $subject, array $ids): void`;
  - it goes through the existing `AuditRecorder` and commits before anything else happens;
  - it throws if the row was not written; `AuditRecorder` must throw rather than swallow the error, and a test forces
    a failure;
  - two named allowlists in the class, `EVENTS` and `ID_KEYS`, so no content can slip in. Amirul's
    `consultation.reviewed` is added to them deliberately in his PR.
- `resources/js/components/ReadOnlyBanner.vue`: the text above and a Back slot. It uses the theme tokens, readable
  in light and dark.

## 4. What the page is built from

- A new `DispensaryReviewService::project()`, built as an **explicit list of fields** for every case status: pending,
  dispensing, waiting for the doctor's check, returned to doctor and completed (Hakim).
  - It reuses neither the Completed page's data nor the open-case `detail()`.
  - The Completed page's data carries `billingUrl`, `labelUrl` and returns-panel fields worked out from the viewer's
    permissions, and lacks the order beside what was dispensed.
  - `detail()` carries lock versions, `can` flags, stock availability and search data.
- The layout of ordered beside dispensed follows `VisitHistoryService`, which already lays it out this way.
- The payload holds no URL, lock version, token, idempotency field or `can` flag. A test pins its keys.
- The page is a new `pages/Dispensary/Review.vue`. It shares the Completed page's read-only table partials rather
  than copying them.

## 4a. Recorded once per real view (Hakim)

- The controller calls `ReadOnlyReviewAudit::record()` first and lets it commit. Only then does it build the page
  data, because Inertia works out page data when it builds the response.
- If the audit write fails, the response is a 500 whose body contains no patient name (tested).
- The "Open dispensary case (read only)" link turns off Inertia hover prefetch.
- The page does no partial reloads, so every request to the route is a real open and is recorded.
- `no-store` keeps the browser from saving the page. A page restored by Back from the browser's memory without a new
  request is not recorded, because nothing new is shown. A reload or a new open is recorded.

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
- A forced audit failure returns a 500 with no patient name in the body.
- Prefetch is off on the link (frontend test).
- Posting to `returns/{id}/check` as the Director is refused and writes nothing.
- The review data holds no call URL.
- Every other role gets 403 on the review route. That includes CA and CA Supervisor, who keep their normal pages.
- The source test for GET only, and the permission catalogue and the migration (additive, idempotent).
- **Existing assertions that will fail and are left failing for the owner:**
  - the Director's permission list in `tests/Frontend/fixtures/access-control-roles.json`;
  - the backend role-permission pins that list the Director's permissions exactly.
  - The final list will come with the build.

## 7. Question for the owner

- Approve `dispensary.view_readonly.organisation` for the Director only? (Recommended: yes. It is owner decision 4.)
