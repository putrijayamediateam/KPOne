# Shared domain

This namespace is reserved for genuinely cross-domain primitives, contracts, and read-only aggregate
projections such as Insights reporting.

Domain-specific behavior and all mutations stay in their owning Organisation, Identity, Access, Patient, Visit,
Queue, Clinical, or Audit boundary. Cross-domain reports may aggregate authorized, minimized data but must not
become a generic dumping ground, duplicate domain write models, or bypass domain services and policies.
