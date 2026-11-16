# V2 Access Control (AccessControl/V2/Index) evidence

Props are a snapshot of the REAL role catalogue (`PermissionCatalogue::roles()`: 13 roles, 235 distinct permissions; Director 152, CA 70, CA Supervisor 111, the TV account 6 and none of them audit.view.organisation). The page itself is mocked, nothing is fetched. Full list, filtered ("audit.view": Director, HR Manager and Technical Admin), no match, and roles folded, at 1440, 390 and 320 px, light and dark (24 shots).

An earlier set of shots used generated permission names whose counts varied by role position (so the TV role looked like it held 117 permissions). That was a fault in the shot data, not in the page or the catalogue; those shots were replaced by this set. `tests/Frontend/v2-access-control-parity.test.mjs` renders Classic and V2 with the same props for every real role and checks the same label, count and permission names.
