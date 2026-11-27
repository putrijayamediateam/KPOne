# Trial Director fixture clock correction

Priority blocker: PR1132 head7457a39d9 backend shard1 job140242827028 fails original TrialDataCommandTest Phase5 success test at its Phase1 prerequisite. Main base d0f37b5c already includes prior Phase5 clock scheduling correction. This patch changes only tests/Feature/TrialDataCommandTest.php; no Registration UI or production source.

Observed cause: external diagnostic reused the actual test setUp and command, captured actualUTC2026-11-27T03:20:53Z then froze KL2026-11-26T12:00+08. Primary Cheras assignment valid_from2026-11-27; secondary branches2026-11-26. Real phase1 exited1 before host prompt with 'The Director has no current assignment at CHERAS. Run `php artisan kpone:director-branches` first, then try again.' See refusal.json and diagnostic1test/2assertions log. This is new Phase1 evidence, not the historical Phase5 incident.

Correction: synthetic primary assignment begins yesterday, matching the existing secondary branch fixture helper, so all configured past-day clock cases have valid fixture coverage. The production bootstrap and current-assignment guard remain unchanged.29added lines only, all old assertions preserved. New positive regression runs actual Phase1 on prior branch day and checks an actual generated fictional account; new negative regression deliberately places primary coverage after frozen day and verifies exact refusal plus unchanged counted tables.

Focused3tests/48assertions pass (new positive/negative and original noon Phase5 success), PHP syntax/Pint/diff pass. Full class including4midnight cases pending at checkpoint. Staff independent source review clear, final gate/hash review pending. Isolated own kpone_trial_director_clock_test only; no production/env reads.

Resource interruption: sandbox initially failed with inode exhaustion, not application failure; only reproducible owned obsolete vendor copies removed and interrupted new vendor copy repaired from another owned tree. No source/data/evidence cleanup. This does not affect the captured command diagnosis.

Final fullclass PASS24tests/564assertions exit0 in242.8s, including four midnight provider cases. Source remains b12cfd0d35dd63729409b7b0de42ecb8869cb2a300a5d7fd681e4f813952da67; patchd240cc69458681645873e314195a1567259f69d0c4c17d9697cb8c48ea92423c. Ready independent final pin/publication.

Final Staff independent CLEAR c5b13613/sourceb12cfd0d/patchd240cc69. Published draft#1248 https://github.com/putrijayamediateam/KPOne/pull/1248, remote head305c2033682d00926dfe97a188f74291dd594117, exacttree34b25944832c489489c7e7a908becd8ee2e87dcf parity. Localclean aligned; original archived. Fresh CI run45934479513 queued at publication check, not yet green. No merge.
