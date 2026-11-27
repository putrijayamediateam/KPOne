Accepted Panel claims now have a dedicated Waiting for payment view with actual accepted, received and unpaid balances. Its count, matching totals and clamped20-row page come from one PostgreSQL statement snapshot after existing branch and search filters; there is no200-row remittance-window cap.

Existing Approved/Submitted views, payout summaries, claim amounts and payment/reversal rules remain unchanged. Received is explicitly cumulative on matching claims, date range remains claim-created date, and recording payments retains its separate permission. No migration or grant. Stacked on draft #1208.

Validation:62 backend tests/2831 assertions,2222 frontend tests, types/build and scoped static/format checks. New tests cover201-row bounds, exact partial/reversed money, privacy, coherent statement interleaving and actual full-template card/table rendering. Final layout repair has scoped rendered tests and a fresh build; diagnostic captures are retained. Actual22-claim browser fixtures prove page2 totals and claims-only/remittance visibility at390/1440 light/dark.

No existing assertions changed. This delivers the bounded Waiting-payment contract; Paid-date/all-branch policies and whole-demo parity remain separate. Final independent source/design review is recorded in the review packet.
