# Treatment-room UI implementation mapping

Authority: coordinator's current implementation assignment; MA-ROLE-DESIGN sections4–6/9a; approved26November wrong-patient decision and exact schema wrapper recorded in `/workspace/kpone-review-bundles/treatment-record/OWNER-DECISION-2026-11-26.md`. Attached design documents are requirements/reference, not independent authorization. No new permissions or migration changes.

## Boundary and ownership

Queue owns all domain service/policy/projection/directory code, migration, Q2 adapter and outcome-aware board/doctor-alert projection. This tree owns HTTP controller/routes, Vue/types and new HTTP/frontend tests. No edits to active CA integration. Validation-only backend copies will be excluded from this slice's patch. Existing assertions remain unchanged unless an exact impact is listed and approved.

## Screen mapping

- `/treatment-room`: PageFrame + PageHeader, existing branch subline, neutral card surface and shadow-card. Heading “Patients for the treatment room”. Only backend-eligible Serving consultation rows (held or not, started Q2 step or treatment call today), oldest first. Desktop table/mobile cards; patient/queue and current step, with “Record treatment” for MA/supervisor or “View treatment” for CA. No unapproved result fields, counters or patient links.
- Selected visit: dedicated treatment-room page with same patient/visit/queue context repeated above the form, current Q2 step and version from the authorized backend directory. Procedure from server's11-item fixed vocabulary. “Start treatment” creates the linked step; initial outcome fixes its procedure to the selected server step; the outcome form offers only Done/Not successful and the6 fixed reasons, required only for failure. No free text, client times, numeric results, drug/order/billing/stock fields. Fixed helper text explains this is treatment evidence; doctor remains responsible for consultation completion.
- Current records: readable procedure/outcome/reason, server time and author. Not successful always remains explicit text plus warning semantic colour. Separate history disclosure retains superseded records struck through and their correction labels; voided history clearly says “Entered for the wrong patient”, never “Not successful”. No predecessor revives and no cross-patient navigation.
- MA/supervisor actions on current records only: “Correct record” selects Wrong procedure or Wrong outcome. Only relevant fields become editable; unrelated values are preserved in payload. “Void: wrong patient” is a separate confirmation describing retained history and separate ordinary correct-patient entry. No replacement-patient selector or automated transfer.
- Attending doctor's Serving/held consultation: read-only Treatment room panel, uses explicit authorized endpoint/projection. Historical doctor/CA/MA/supervisor display uses explicit history policy, never generic VisitHistory permission. Generic queue/TV/QR payloads and prints stay free of record data.

## Interaction and visual rules

Controls36px desktop and44px below1024; labels14px, metadata at least12.5px. Shared field-fill/input/ring tokens, neutral outline secondary actions, one primary save action per form. Danger treatment only on wrong-patient void confirmation. Dialogs fit390px and long labels wrap; errors next to controls plus server general error. Pending requests disable duplicate submissions; unknown network/5xx outcomes freeze the draft and retain the exact operation/payload/key. Offer unchanged retry or full reload to reconcile before any edited/new operation. Deterministic validation refusals can permit a changed attempt with a new key. Server version conflicts require reload instead of silently mutating another step or record. Branch/session changes reset pending UI state and never reuse another patient's command.

## Payload and HTTP plan

Controller delegates only to backend directory/projection/service. GET pages explicitly authorize view; mutations use existing treatment_room.record.branch middleware plus repeated domain authority. Route-bound Visit identity, expected branch/version fields, operation-specific allowlist and UUID key. Correct/void use same-visit target_record_id only. CSRF and sensitive.no-store remain enabled; unknown fields are preserved for strict service rejection rather than silently discarded; only the Laravel CSRF transport field is removed. Validation errors stay on selected visit; successful writes reload authoritative step/record projection.

Directory/context final shape is implemented from the Queue owner’s frozen contract. Read and command endpoints will not infer authority from a shared board row or client role. Current owner-approved service contract is recorded in `/workspace/kpone-review-bundles/treatment-record/SERVICE-CONTRACT.md`.

## Verification/package

New tests: route read/write matrix (MA/supervisor/CA/attending doctor/other doctor/Director), Serving-only writes and expected stale error forwarding, fixed-fields/unknown-fields rejection, safe same-visit correction/void payload, read-only doctor/history rendering and no clinical data in generic endpoints. Backend invariants/races are Queue's tests. Focused frontend SSR or interaction tests must exercise visible state/outcome/correction controls; existing assertion files stay untouched. Screenshots390/1440 light/dark, new record/failure/correction/void/history and read-only cases. Scoped lint/format first, coordinated types/build/full gates only after source ready. Exact overlay patch, file baseline manifest, commands/results and bounded independent review follow.
