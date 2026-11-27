The Queue workspace now uses the uploaded interactive demo's visible My patients/Queue heading, grouped navigation, real-count cards, focused patient columns and optional supporting information. Existing emergency, clinical and treatment actions retain their server authority and versions. Own authorized doctor warnings remain visible, and emergency table widths no longer compress the Actions column.

This is a preservation draft stacked on the CA validation archive, not a merge-ready main PR. Only the five-file reviewed v4 presentation overlay belongs to this PR. The archive's TRI2/Treatment/Q16 dependencies need separate extraction and serial integration before merge.

Validation on the isolated exact source: 71 focused frontend tests, TypeScript, scoped lint/format and production build passed; eight doctor/supervisor390/1440 light/dark browser states had no overflow or JavaScript errors. Independent design review closed both findings. Patch SHA256: 1a0924e754fc5c26d444e6e453f1636dd5ce37492fd132f7433bbba20238c787.

Incomplete demo parity is explicit: held/treatment/check datasets and final tab semantics are separate work; TV remains pending. No permission, migration, old assertion change, production activation or deployment is included.
