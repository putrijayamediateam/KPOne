# Navigation polling test — delegated technical decision

26 November2026: coordinator/root relayed the user's latest explicit delegation of technical decisions and authorized a faithful existing navigation test replacement after exact independent review. This is not a separate fabricated owner answer.

Old reg-live-1 test clicked the removed QR entry inside OperationalTabs, then clicked All, asserting request counts0→1→2. Actual implementation now navigates between /registration?tab=qr-intake and /registration. Keeping an invisible fake status button solely for the test would misrepresent behavior.

Exact proposal PROPOSED-route-polling-test.patch was reviewed by Staff/Design and approved in session. Applied unchanged: fixture usePage URL override, QR mount30seconds→0requests, real unmount with0timers/listeners, Today mount withoutimmediatePOST, first10seconds→1 andnext10seconds→2. New browser checks already prove actualLink/backnavigation. Added cleanup assertions strengthen coverage; all other oldassertions remain.

Additional support changes: NullStub mapping for RegistrationWorkspaceNav in four existing component harnesses; three blankline fixes in the new SSRtest for repository ESLint. No production source change in this follow-up. Focused40cases pass after exactproposal. Full finalgate and publication heads recorded in REVIEW/handover.
