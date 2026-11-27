# HR proposal from the clinic HR (received 2026-11-05) — parked

Source: "Cadangan Sistem HR & Dashboard Klinik Putrijaya", 13 pages, prepared by HR, November 2026.
Owner instruction 2026-11-05: keep it; we come back to it later (no HR system exists in KPOne yet).
No personal data in the PDF (checked: no names, phone numbers or emails).

## Three parts
- A. Side HR: roster and shift swaps, minimum staffing alerts, clock-in (geofence + selfie), roster vs attendance,
  forgotten clock-out, attendance patterns, twice-daily shift reports, live dashboard, cost and locum budget,
  monthly summary, director approvals, probation/onboarding, letters and discipline, OT (Employment Act 1955
  1.5x/2x/3x), 45-hour limit, leave, payroll export to MySyarikat, e-memo, appraisal, recruitment, turnover, PDPA access log.
- B. Clinic Compliance: resident doctor roster with mandatory coverage, work-hour limits, fair rotation, swaps;
  staff licence status (APC, BLS/ACLS, Hep B; 60/30/7-day reminders), clinic documents, SOP acknowledgement,
  KKM audit file export, incident reports, internal audit checklist, vaccine fridge temperature log.
- C. Locum: slot portal (OTP, valid APC only), reminders 48h/24h, reconfirm, waiting list, emergency broadcast,
  cancel policy (72h/24h), scoring, WhatsApp access.
- KPIs and a three-phase plan (Phase 1 MVP: roster and attendance, shift reports and dashboard, resident doctor roster).
- Open questions for developers: MySyarikat API or export, official WhatsApp API or copy-paste, web or mobile app,
  cost and timing, hosting.

## Already in KPOne that touches this
- Staff identity, roles and branch assignments (Phase 0B); clinician credentials with MMC number (DOC-02a).
- BASELINE section 4: HR workflows and staff roster are NOT authorised. HR readiness analysis merged in PR #172
  (docs/reports/ANALYSIS-HR.md, roster-first sequence).

## Conflicts with current rules (need owner decisions before any build)
- WhatsApp notifications/broadcasts, OTP phone login and email notifications: unauthorised (AGENTS.md; only WA-01
  click-to-chat is allowed).
- Geofence and selfie clock-in: location and biometric-like data; needs PDPA review.
- Locum external accounts (outside staff provisioning; "unknown identities must never create users").
- Payroll/MySyarikat integration: external integration, unauthorised.
