# Patient duplicates at the pilot branch (staff procedure)

Status: **draft for the clinic's review**. It describes what KPOne does today, so the procedure matches the real rules.
It is not an approval, and nothing here makes KPOne production-approved. Owner decision, 15 Nov 2026: leave the override
rule as it is for now (see "What KPOne does not do yet").

## What KPOne does today

1. **No IC number or passport number means the patient stays in Yezza.** KPOne refuses to create a patient without exactly
   one IC or passport number, on the Patient create screen, in Registration Quick create and when accepting a QR check-in.
2. **KPOne looks for a likely duplicate** when a new patient is created: the same registered name with the same date of
   birth, the same name with the same phone number, or the same date of birth with the same phone number. The same IC or
   passport number is a separate, stricter check and is always refused.
3. **If KPOne finds a likely match it stops** and asks the person creating the patient to confirm a separate patient. The
   confirmation is a tick ("Confirm only if ..."). **Any CA can tick it.** KPOne records only that the tick was used (a yes
   or no in the audit record). It does **not** record who approved it or why.
4. **Merging two records is a two-person step.** A CA or CA Supervisor proposes it, a different CA Supervisor or Director
   approves it. Nothing is moved or deleted, and a merge can be undone with the same two-person approval.

## Staff procedure

1. **Search first.** Before creating a patient, search by name, IC or passport number and phone number. Open any likely
   match and compare it with the person in front of you (name, date of birth, phone, IC or passport).
2. **If the person is the same, use the existing record.** Do not create a second one.
3. **If KPOne shows "possible matching patient records" and you believe this is a different person,** ask a **CA
   Supervisor** to look at the match with you **before you tick**. Tick only after the supervisor agrees.
4. **Write the reason down.** KPOne has no free-text place for it, so record who agreed and why in the clinic's own record
   for the visit (for example the supervisor's log or the Yezza visit note). **[To confirm with the clinic: where this
   note is kept.]** Typical reasons: twin or family member with the same date of birth, a different person with a
   shared family phone number.
5. **A person with no IC or passport number** is registered in Yezza only. Tell the person at the counter that KPOne
   cannot register them yet.
6. **If a duplicate was created by mistake,** do not edit it into the other record. Ask a CA Supervisor to start a merge
   (two people are needed).

## What KPOne does not do yet (owner deferred, 15 Nov 2026)

- It does not limit the override to a CA Supervisor: a CA can tick it alone (the rule above is a **procedure**, not a
  system rule).
- It does not store a reason or who approved the override.

The design for both (a new permission for CA Supervisors only, and a recorded reason) is logged in the Patients &
Registration TEAM.md backlog. It needs the owner's approval of a new permission before anything is built.
