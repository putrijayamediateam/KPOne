Prepare and Waiting rows now offer Visit details with the reason excerpt and saved coverage label already authorized by the existing Dispensary board. The reader preserves snapshots and legacy fallback, batches the two reason relations and omits context from Done. No demographics, panel notes, clinical history, new permissions or actions are added.

Native disclosure keeps its open state and keyboard focus during polling. If polling removes the focused case, focus returns to the stable cases region; routine refreshes do not steal focus. Existing case/label actions remain outside the disclosure. Three existing test harnesses accept the new component import without changing assertions.

Validation:11 PostgreSQL tests/200 assertions,16 frontend tests, PHPStan/Pint/scoped lint/format, full types/build, and14 responsive synthetic browser states. Actual polling proves disclosure persistence, focused-row removal and outside-focus retention. The removal fixture uses the existing sendForDoctorCheck service in an isolated synthetic clone; browser activity itself is GET-only. Independent backend and Design review recorded in the local packet.

Stacked on #1231. This is a bounded existing-data disclosure, not full Pharmacy parity. No merge or production activation.
