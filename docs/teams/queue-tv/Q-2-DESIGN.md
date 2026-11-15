# Q-2 Treatment progress: design note

Status: **owner decisions recorded 2026-11-05 (section 4). Nothing is built.** Queue & TV team (Amirul, Lead; Mira, Reviewer;
Yasmin, designer). Backlog item 2 in `TEAM.md`, from the owner's parked staff-communication idea (b),
`docs/BASELINE.md` section 6 item 12.

## 1. In plain words

A doctor often puts a patient **on hold** while a treatment runs (for example a nebuliser) and sees the next
patient meanwhile. Today nobody can see on the board whether that treatment has started or finished, so the doctor
has to ask or walk over.

With Q-2:

1. A CA (or the doctor) marks a step on the patient: **"Nebuliser: started"**.
2. The Queue board and the consultation page show it, with how long ago it started.
3. When the CA marks it **done**, the attending doctor gets a pop-up (and the optional sound): _"Treatment done:
   A-012 Nebuliser. Ready to resume."_
4. When the doctor resumes the patient, the "Ready to resume" note clears.

**The waiting-room TV shows nothing of this.** It is a working note for staff, not a medical record, and it
never changes a bill.

## 2. What it is and is not

| It is                                                                                                    | It is not                                                                                          |
| -------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| A short, fixed list of steps (no free text)                                                              | A clinical note, diagnosis, or medicine record                                                     |
| Kept for good as an append-only history (who, what, when)                                                | Billing evidence: the bill still comes from the services the CA confirms at Dispensary (unchanged) |
| Shown only to staff who already see the branch queue, and to the attending doctor for their own patients | Shown on the TV, the public QR page, Insights or any report                                        |
| Allowed only while the consultation is in progress or on hold                                            | A replacement for the treatment-room call (that stays as it is)                                    |

## 3. Screens (Yasmin's sketch)

**Queue board, Status column** (no new column, so the table does not grow wider):

- **Started:** blue chip with an activity icon, "Nebuliser: started 12 min ago". Blue is new on the board, so it
  is not confused with amber (waiting, on hold, not ready).
- **Done:** green chip with a tick, "Nebuliser: done 3 min ago · Ready to resume".
- Below it a 44 px button, the same style as "Mark not ready": **"Start treatment step"**, then **"Mark done"**,
  then **"Start next step"**. The row menu also gets "Treatment progress…" in case the cell is cramped.
- A small dialog to choose the step. The text reads: _"This is for the board only. It is not a clinical record and
  is not shown on the TV."_
- **Mistakes:** nothing is deleted. A quieter **"Cancel this step (entered in error)"** with a reason (wrong
  patient, wrong step, marked too early) removes it from the board and keeps both rows in the history.
- **Phone (390 px):** the Status part of each card takes the full width so the chip and button are not squeezed.

**Consultation page:** a small read-only chip beside "Active X min · Held Y min", plus the same button next to
"Ke bilik rawatan". The "patients waiting" dropdown adds one count, like the not-ready count: _"1 treatment done,
ready to resume"_.

**Registration board:** nothing in the first version (it belongs to the Patients & Registration team). Adding the
same chip later is a one-line change if you want it.

**Wording** is English, like the other queue features. Error, busy and "someone else changed it" messages follow
the existing not-ready flag.

## 4. Owner decisions (2026-11-05)

1. **Steps:** Nebuliser, Wound dressing, Injection and observation, IV drip, Observation after medicine, ECG,
   Blood test (point-of-care), Other procedure.
2. **Each step has started and done.**
3. **Who marks:** CA and CA Supervisor at their branch, and the attending Resident Doctor on their own patient.
   The owner approves the new permissions in section 5 for a migration.
4. **What clears "Ready to resume":** the doctor pressing Resume, or opening that patient's consultation.

Taken as the recommended defaults (the owner may change them before the build):

- Only the attending doctor gets the "done" pop-up.
- There is no warning for a step that runs too long in the first version.
- There is no Registration board chip in the first version.

## 5. For the builders

- **Domain:** `app/Domain/Queue/Treatment/`, mirroring `Readiness/` (the not-ready flag): a service with fresh
  permission and branch checks, `DB::transaction`, row locks on the queue entry, `lock_version`, an idempotency key
  and a structural audit row (step code and action only).
- **Data:** a `queue_treatment_steps` row per step (entry, step code, state `started|done|cancelled`, lock
  version), plus append-only `queue_treatment_step_events` (`started`, `done`, `cancelled`, `acknowledged`; actor,
  doctor, time).
    - PostgreSQL checks: a controlled code list, at most one open step per entry, and events that cannot be
      updated, deleted or truncated.
    - A trigger refuses a step unless the entry is a Serving consultation of the same organisation and branch.
    - Timestamps are UTC. The migration timestamp comes from LUCY.
- **Rules:** steps are allowed only while the queue entry is Serving (held or not). The step closes itself when
  the entry leaves Serving, recorded as a system event the way the not-ready flag closes.
- **Permissions (owner approval needed):** `queue.treatment_progress.set.branch` for CA and CA Supervisor, and
  `queue.treatment_progress.set.own` for the Resident Doctor. Viewing reuses `queue.view.branch` and
  `queue.view.own`. Technical Admin, Administrator and the TV account get nothing.
- **Alert:** a doctor-scoped event cursor endpoint, like `/queue/readiness-updates`. The first poll only sets the
  cursor, so a refresh never repeats a pop-up.
    - The toast and sound reuse `useCallReadinessAlerts`, with the same sound switch, and a two-beep tone so the
      doctor can tell it apart.
    - Resume, or the doctor opening the consultation, writes an `acknowledged` event.
- **Payloads:** the board row gains a small `treatmentStep` object (code, label, state, startedAt, doneAt,
  lockVersion, can). The consultation dropdown gains one integer only. The TV feed and the QR status page are
  untouched, which tests will pin.
- **No change to:** queue order, Call In, Hold or Resume rules (apart from writing the acknowledgement), the
  treatment-room call, the Treatment Plan, `ServiceDelivery`, Dispensary service lines or billing.
- **Tests:** domain and PostgreSQL evidence tests (append-only, one open step, scope trigger), permission and
  branch denial, idempotent retry, stale lock, no repeat alert after refresh, TV and QR free of the field, frontend
  tests, and screenshots at 1440 and 390 px in light and dark, checked by Yasmin.
- **Size:** one PR of about the same size as the not-ready flag (PR #177).
