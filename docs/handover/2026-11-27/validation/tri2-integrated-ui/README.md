# TRI2 integrated UI fixture — executed once on synthetic preview

`prepare-fixture.php` is a one-shot synthetic domain-service setup for `/workspace/KPOne-ca-integration` and exact PostgreSQL `127.0.0.1:55439/kpone_ca_preview_test`. It refuses another environment/database, missing TRI2 tables, missing actor authority, an already busy current doctor, reserved existing patients, or any prior marker/output. It does not run migrations, activation, DB reset, configuration changes, factory writes, or read a real .env.

Coordinator runs ONLY after integrated UI/build readiness, using the established explicit synthetic environment wrapper:

```sh
bash /workspace/kpone-validation/ca-integration/preview-env.sh php /workspace/kpone-validation/tri2-integrated-ui/prepare-fixture.php --prepare-synthetic-tri2
```

Prerequisite: an existing approved synthetic triage guideline. Missing guideline fails closed; this script does not invent approvals or replace the guideline. Actors come from `ca-smoke/fixture.json`, all `.test` and scoped Puchong. A separate fictional resident doctor is provisioned through StaffProvisioningService only if absent. Existing current room is reused, otherwise selected/created through room services.

Creates exactly three synthetic patient/visit/queue flows through real services: one Serving consultation for the current doctor, baseline saved note, unheld; two red Waiting visits assigned to the other doctor with automatically raised clinical emergency episodes. Waiting visits deliberately have NO ClinicalEncounter yet—the domain starts one only after ordinary Call In. No Hold/Seen/Take action is preperformed; those are for real browser proof.

A single DB transaction encloses creation and records audits; existing participant user/profile/assignment locks are acquired in canonical order. Exclusive filesystem marker refuses concurrent/repeated setup. On any failure the marker remains and identifies preflight/transaction/committed phase; do not automatically delete it or rerun. Database commit precedes atomic evidence-file replacement, so a file-write failure must be inspected rather than reseeded.

`fixture.json` will contain actor/branch/room/visit/queue/encounter/triage/episode IDs and before-state/version evidence. No passwords are printed or written there. The UI can test unsaved-note Save/Discard, explicit Hold then Take, independent Seen, retries and patient identity boundaries by those IDs.

Initial preparation used source inspection and PHP syntax only. After explicit coordinator authorization, the dedicated 3054 wrapper was used for read-only preflight, synthetic guideline workflow and one fixture execution. Evidence below supersedes the earlier prepared-only status.

## Execution evidence — 26 November 2026

`preflight-inspection.json` proved exact loopback port 55439 synthetic database, no guideline rows, zero reserved patients and zero reserved other-doctor identity. The failed preflight marker was archived after this check. No existing draft was discarded.

`prepare-synthetic-guideline.php` used actual enter/approve services with existing fictional HR actor 10 and Director actor 2. Guideline 1 is a test workflow artifact only; read `NOT-FOR-CLINICAL-USE.md` and `synthetic-guideline.json`. Then the fixture ran once successfully, marker complete at 04:52:59 UTC. `fixture.json` records all IDs and before-versions; `fixture-run.log` records success.

Serving current visit KPV-00000003 (queue 3, encounter 3, doctor 18, room 3) is unheld with a saved synthetic baseline note. Red Waiting visits KPV-00000004 and KPV-00000005 (queues 4/5, episodes 1/2) belong to other doctor 21 and remain untaken. No activation command, migration, reset, production access or config modification occurred. Do not rerun; preserve these cases for coordinator-owned browser evidence.
