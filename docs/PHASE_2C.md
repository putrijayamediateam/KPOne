# Phase 2C — Operational Shell & Clinical Workspace

Phase 2C is a presentation and navigation refactor. It does not add or change Patient, Visit, Queue, Encounter, Allergy, Problem, or Treatment Plan state.

## Product contexts

- **Clinic workspace:** header-led Registration, Consultation and planned-module navigation for authorized clinic actors. It has no persistent sidebar.
- **Main Menu:** `/dashboard` remains the authorized launcher for Staff, Branches, Access Control and Audit Logs. The KPOne logo returns here.

`GET /workspace` resolves the safe default landing on the server. Resident doctors land in their own Consultation Queue; CA and CA Supervisors land in Registration; management and Technical Admin users land in the Main Menu. Multi-role clinical access is derived from the explicit `resident_doctor` role, not seniority.

## Patient Board

Registration presents a dense structural Visit/Queue board. Waiting and Serving Now map only to released Queue states. Cancelled maps only to Visit cancellation. Dispensary and Completed are selectable planned states with no records or backend state.

Queue remains `/queue` for compatibility and is labelled Consultation. Its own-versus-branch scope is selected by the backend. Browser input cannot broaden that scope. Board polling contains no clinical note, vitals, diagnosis, allergy, problem, or Treatment Plan data.

## Clinical workspace

Desktop consultation uses a current-care/history split. The current side retains all released Encounter, clinical-safety, and Treatment Plan forms. Initial history data remains limited to 15 structural summaries. Full historical detail loads only after selection through the existing authorized history route and explicit JSON projection. Each request revalidates the released current-care or authored-history relationship and returns privacy-preserving denial. Historical Treatment Plans are not included.

## Routes and compatibility

Existing `/dashboard`, `/registration`, `/queue`, Patient, Visit, Queue, Clinical, Staff, Branch, Access Control, and Audit routes remain. Phase 2C adds `/workspace` and lightweight `/reviews`, `/panel-claims`, `/insight`, and `/purchase` placeholder pages. Placeholder pages have no APIs, records, analytics, or persistence.

## Privacy and non-goals

Navigation is not authorization. Existing policies, permission middleware, branch isolation, own-doctor Queue scope, current-care checks, clinical privacy, audit minimization, optimistic locking, and transactional mutation rules remain authoritative.

Phase 2C does not implement Dispensary, completion, payment, Billing, historical Treatment Plans, or Phase 3A behavior. No migration is required.
