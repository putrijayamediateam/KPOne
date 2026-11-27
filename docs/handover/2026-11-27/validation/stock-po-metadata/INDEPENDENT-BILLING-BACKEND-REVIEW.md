# Independent Billing review: optional PO metadata

Pinned frozen patch3b1712c867d80aa60cdad04725ea0bce3df8244ce558780e18bea135e7bb957d, all12 manifest file hashes independently verified. Approved migration76d874383747b49f34bc94e87a9ca90e63f57ae6899a4236a224e4adb0ac96c7 unchanged. Bounded backend source review clear; UI/browser and author runtime gates not independently rerun.

Nullable date/reference additions preserve old rows and omitted-client updates; explicit null/blank clears. Domain validates scalar shape before normalization, then date plus exact format and bounded/control-free business string. Final year0000 negative was read alongside added date rule, preventing SQL-only invalid-date failure. Fresh locked branch/actor/order version and draft status remain before writes. Supplier/location/SKU authority and existing lines/financial transitions unchanged. Controller accepts metadata only for create/update, not approval or receipt commands.

Separate UPDATE-only trigger checks actual metadata value changes, requires old/new draft and version+1; unchanged lifecycle transitions and INSERT are untouched. Columns are guarded and down preserves metadata/guard. No existing approval/short-close guard replaced. Directory/print add date-only/reference fields under existing scope and cost visibility. Audit records field names only, not raw values; field-name list denotes supplied normalized keys, not necessarily changed values.

Read new validation, stale/non-draft, raw guard, up/down/up preservation and non-cost print tests. Author reports40/426 focused plus final7/73 and actual UI evidence; attribution only. No permission or stock/money change found. This is source clearance for the approved artifact, not production migration execution.
