# Classic Queue & TV: every function today (for V2, so nothing is lost)
Source: origin/main at the time of writing (Queue/Index.vue, PatientBoard.vue, components/queue/*, QueueDisplay/Settings.vue, Clinical/Show.vue queue parts). Roles: CA, CA Supervisor, Director see the branch board; a Resident Doctor sees own queue only ("Clinician scope"); TV account sees only /queue-display (screen).

## A. Consultation queue board (/queue)
Page: "Consultation queue" (hidden h1 in Classic), "Live · every 3 seconds" line.
1. Tabs with counts: All active, Waiting, Serving Now, Consultation Completed. Tab strip scrolls on phones with a right-edge fade and scrolls the chosen tab into view.
2. Live refresh: polling every 3 s (backs off to 6/12/24/30 s after failures), pauses when the tab is hidden, resumes with an immediate refresh; stale responses discarded; refresh keeps the filters and both pagers; clock for waiting times ticks every 60 s.
3. Filters: patient or exact Queue number search; doctor select (branch scope only); urgency select; Apply; Reset; draft versus applied filters with a validation error line.
4. Doctor's "My room today" bar (doctor, branch with rooms): room select, warning state before a room is chosen ("Call In stays blocked until you do"), "no rooms set up" hint, "The TV shows this room…" hint, save error.
5. Counts line: "N visible entries/entry", "Clinician scope" or "Branch scope".
6. Notification sound toggle (on/off): ready-to-call chime and treatment-done chime; toasts for "ready to call" and "treatment done".
7. Call next (doctor own queue: own patient; Director/CA Supervisor: choose a doctor and Apply): confirm dialog naming the patient, Urgent/Carry-over labels, server re-check of the same patient and versions, 1 s Call hold when the next patient changes, disabled-with-reason states (busy doctor, no room, nobody ready), polled block (GET /queue/call-next).
8. Board rows (shared PatientBoard): patient name + KP number; Queue number (A- consultation, B- OTC); Arrived date and time; Visit notes (clamped, tooltip); Doctor chip (tooltip); Payment (coverage, Panel name, Panel note with tooltip); Duration chip (Waiting/Serving, amber from 30 min and red from 60 with sr-only text); Status chips (Waiting, Serving Now, On Hold, Urgent, Returned, cancelled/removed, Not ready to call badge with reason, treatment-step badge and "ready to resume" chip); WhatsApp click-to-chat icon (audited redirect); Actions menu.
9. Row actions menu: View visit; View patient; Edit visit; Treatment progress…; Send to Waiting; Call In (also "Call in anyway" for a not-ready flag by the doctor with reason); Call again (Panggil semula); Call to treatment room; Open Consultation; Call to Dispensary; Dispense; Open Dispensary; Cancel Visit; Mark / Clear "Not ready to call" (CA, CA Supervisor; fixed reasons; doctor override with reason).
10. Dialogs: Call this patient? (QueueCallDialog: urgent / at the door reasons for out-of-order calls); Call to a treatment room (room select); Cancel Visit (VisitCancellationDialog with reason); Not-ready flag set/clear/override dialogs; Treatment step dialog (8 steps, start, done, cancel with reason "Entered in error?").
11. Pagers: Waiting today (Previous today / Next today) and Carry-over (Previous carry-over / Next carry-over); carry-over rows come before today's only by rule, shown with their original queued time.
12. Footers: "Showing the latest 25 of N" under Serving Now and Consultation Completed.
13. Refused-action messages stay on the board (operational error line); busy state disables other row actions while one runs.
14. Empty states and "Live refresh paused" style failure handling.

## B. Consultation page, queue-related parts (Clinical/Show.vue)
Hold / Resume (max 3 held per doctor, 30-minute held warning), unsaved-work guard before Hold (Save and hold / Discard and hold / Cancel), held notice with Call next (held consultation only), "patients waiting" dropdown with counts (waiting, not ready, treatments done ready to resume), Panggil semula (recall) and "Ke bilik rawatan" (call to a treatment room), treatment-progress chip and "Resume" clears ready-to-resume, ready/treatment toasts, My Queue back link, Urgent chip, patient card with payment badge and Panel note, record-changed panel, Focus mode (top bar hidden).

## C. Queue Display (TV) settings (/queue-display/settings)
Branch select (Director all; CA Supervisor assigned branches). Rooms: add (type consultation/dispensary/treatment, name, order); edit name/order; activate/deactivate; status chip; table from 1024 px (cards in the restyle only). Screen content: "Call patients by" (Queue number, Number and short name, Patient full name) with privacy help text; scrolling text (500); YouTube link (300); seconds per poster (5 to 120); Save (versioned, retry-safe). Posters: up to 8, choose file, upload, remove, order; private disk. Preview screen link (opens /queue-display).

## D. The TV screen (/queue-display), for completeness
Now calling / Last called (after 15 min), recent calls list (one row per patient, service and room), recall label, doctor/dispensary/treatment calls with room, short-name or full-name or number-only mode, chime (ding-dong) and spoken call (Malay digits when a Malay voice exists), posters carousel, muted YouTube loop, scrolling text, clock, Full screen button, light/dark button, sign-out confirm, wake lock, remember-me so it stays signed in, 5 s polling with 30 s retry, "Reload each TV after deploy".

## E. Shared behaviours to keep
Permissions and branch checks stay in the backend; idempotency/lock_version on every write; explicit redirects; 44 px targets below 1024; English text; no patient data in the TV feed.
