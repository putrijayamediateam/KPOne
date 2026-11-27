# First independent Queue comparison

Reviewed canonical live-wrapper capture `../queue-demo-makeover/demo-doctor-1440.png` against integrated pre-makeover application capture `../filter-targets/doctor-queue-1440-light.png`, both1440 light and resident-doctor context. Different synthetic patients/branches/states mean counts and row content are not expected to match. Neither screenshot represents a new implementation's parity pass.

Concrete gaps observed:

- Demo has visible “My patients” heading and room/doctor/branch context; application begins with alert and room strip, without comparable visible page heading.
- Demo groups own/other views and My patients/Needs me, then status tabs. Application presents flat All active/Waiting/Serving/Completed tabs. These require real role/scope mapping, not copying tabs with fabricated data.
- Demo has four summary cards and a compact central list with right-side About this tab, Today at a glance, Related pages and Good to know disclosures. Application is full-width stacked controls and a full-width board without that supporting column.
- Demo places Call next in header/context and uses row call actions; application places its Call next below the filter. Preserve existing server call eligibility, confirmation and state behavior when repositioning.
- Demo list distinguishes zone, reason, waiting and patient context directly. Current shared board exposes a different operational column set. Any new content must come from authorized actual DTO fields; cross-doctor clinical information must never be inferred from demo sample rows.

The integrated Emergency alert and independent Emergency lane represent later approved clinical behavior and must be retained. Their extra vertical space is not itself a defect to remove for screenshot similarity. Likewise explicit36/44 accessibility minima override smaller drawn demo controls.

Conclusion: the prior Queue presentation is not full-demo parity. Existing palette/touch-target corrections remain useful foundation; a bounded page composition/content implementation and new side-by-side state evidence are required. Top grouped pills/tabs are confirmed; earlier source-only “left rail” wording was incorrect and has been removed from the coverage index.
