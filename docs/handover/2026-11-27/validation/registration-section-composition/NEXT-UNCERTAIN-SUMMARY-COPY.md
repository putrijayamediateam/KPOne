# Narrow follow-up after section publication

Independent Staff review noted that the default Check-and-register sentence ('Nothing is saved until you register the visit') is unclear after an attempted registration with an unconfirmed result. Existing outer warning and unchanged-retry action are correct; the summary should describe that state consistently.

Own RegistrationReviewSummary.vue: optional `unconfirmed` prop defaults false; when true say 'Review the original details retained for unchanged retry. The registration result is unconfirmed.' Keep current default description unchanged. Own RegistrationCreateForm.vue template passes the existing submissionNotice truth value. Entire form script/immutable command capture/locks remain identical, and no new alert region or backend change is needed.

Add a real summary render test proving uncertain copy omits the normal unsaved claim and retains original patient/visit rows with no controls. Verify an actual lost-response dialog at390/1440 light/dark using another isolated synthetic clone or read-only controlled outcome, preserving exact-key retry proof from parent. Peer review before publication. Separate scoped stacked draft; do not alter frozen section packet.
