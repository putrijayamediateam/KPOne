# Independent extraction review — source checkpoint

Source checkpoint clear for initial patch38a0a411e5115b90b6f3e40b04ce23b86a81e665908403caf16abc2e5f00a31a; final browser and complete gates pending.

Independently verified all22 manifest file hashes and entire const-props/state/handlers byte parity against base46ca8f197. Page retains metadata/main/header and mounts exactly one unkeyed RegistrationCreateForm with direct reactive prop bindings. New component owns all existing form state and DOM, with no extra wrapper or modal behavior.

Independently ran four new actual Vue/Inertia runtime tests: preserved-state props retain draft/refusal and single instance, pending search/timer cleanup on unmount, single submit and existing error-focus callback, fresh mount creates fresh form. All4 passed.

Reviewed faithful source-path relocation proposals under delegated technical authority. Initial missing dynamic unknown-DOB read and accidental public-path rewrite were caught before application, corrected in coherent regenerated diff. Three additional dynamic-path updates preserve Patient/PublicCheckIn effective paths and knownMinor/Gender-sex/MY assertions. New runtime wrapper test must continue reading actual page, not form twice; inspected current test source confirms this.

No change to clinical, identity, guardian, permission or persistence expectations is authorized by test relocation. This checkpoint is not full-page visual or navigation parity clearance; await final browser evidence and frozen artifact.

## Final source checkpoint

Verified final patch9858bcab6585c15612d10778ec0f4c71121657beb1dd8123c39357174a3e564f and all24 manifest files. The two additional approved dynamic-path relocations preserve original theme/nationality/public-path assertions. Inspected saved-patient1440light and server-refusal390dark captures and builder browser-results: retained form node/draft after real rejection, Back/Forward, no JS errors; one rejected POST reported with unchanged visit count. Full-page refusal capture stitches sticky bars after focus scroll, so requested one viewport-level error visibility check before final visual closure. This is an evidence limitation, not a demonstrated source regression.

## Final visual closure

CLEAR final9858bcab6585c15612d10778ec0f4c71121657beb1dd8123c39357174a3e564f. Inspected server-refusal-focused-390-dark-viewport and refusal-focus-geometry.json: actual assigned-doctor focus at y616/44px, error y664/20px, both visible and unobstructed with scrollY0. This closes the full-page stitching evidence gap without a source change. Builder used a fresh rejected synthetic request because prior session had closed; no successful registration claimed. Final full frontend2213/2213 reported passing. Exact final source/manifest, four independent runtime tests and bounded visual/lifecycle evidence support extraction clearance; no modal or full demo parity is claimed.
