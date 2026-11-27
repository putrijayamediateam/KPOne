# Auth fixture independent refresh review

CLEAR exact `c359d0cb56e22dc7c30fb874d3cccc7c7d495a5e`. Main-relative diff is one line in EmailVerificationTest only: invalid route ID123 becomes the authenticated fixture user's ID+1. Current file SHA25653997509451993605b9671802381ab2d5c80c3ff21c260f1f65c723fea0a6cef independently matches the original publication manifest.

This guarantees the signed route ID differs from the authenticated fixture even if the database sequence reaches123. The test need not prove that the mismatching ID is globally nonexistent; it asserts the authenticated user's email remains unverified. Signature generation, email hash, request and assertion are unchanged. No production authentication code, permission or assertion weakening. Diff whitespace check passes. Coordinator six tests/16 assertions and Pint are reported evidence, not rerun here. No merge performed.


## SUG-main refresh

CLEAR exact `6fc60aa4dda3c22e6e81c8fa2f22672e62a60e80`. Independently verified current main `f8dd19cb83a6129479246db1c62d0af12cd28fb5` is an ancestor; main-relative scope remains only `tests/Feature/Auth/EmailVerificationTest.php`, whose Git blob is identical to the previously independently reviewed head `c359d0cb56e22dc7c30fb874d3cccc7c7d495a5e`. Read the final one-file diff; fixture semantics and all assertions remain unchanged. Coordinator focused test results are reported evidence, not rerun for this unchanged-blob refresh. No production change or merge.


## BIL38-main refresh

CLEAR exact `ad8077914bc6a941ad1b2bda2fc3bf761066252e`. Independently verified main `375f56c39a4a492016cf8c9a9b3472904a1b03f5` ancestry, sole EmailVerificationTest scope and identical scoped blob against reviewed `6fc60aa4dda3c22e6e81c8fa2f22672e62a60e80`. No authentication product or assertion change; coordinator focused tests/remote CI remain separate gates.
