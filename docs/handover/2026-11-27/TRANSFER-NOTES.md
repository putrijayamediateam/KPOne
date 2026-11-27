# Documentation transfer notes

This branch contains a documentation/evidence archive only. Application source files were not copied into the handover tree.

## Included

- `HANDOVER-TO-CLAUDE.md`: full 1–12 handover plus the requested dated owner-decision register and open-PR provenance table.
- `validation/`: 1,526 Markdown/JSON files from the validation workspace, preserving the same relative paths. The current handover was also copied into `validation/handover-2026-11-27/` after its paths were rewritten.
- `older/docs/`: the prior bundle’s documentation tree preserving its source-relative path, plus the user’s uploaded ZIP reference under `older/docs/handover/2026-11-26/` (621 files total). Four executable helper scripts embedded in the old docs tree were excluded to keep this branch documentation-only. This contains the prior handover/evidence archive, design docs and its original screenshots. All 373 screenshot files were ≤1 MB; no screenshot was omitted by the size rule.

The validation archive contains only Markdown/JSON because those are the linked evidence types specified for that tree. The older docs bundle contains 621 files after excluding four executable helpers, including 373 screenshots, all under or equal to 1 MB. The HTML reference file exceeds 1 MB but is an HTML design artifact, not a screenshot, and was explicitly supplied as the canonical reference. The ZIP archive is the uploaded demo artifact, not a screenshot.

## Excluded

- `validation/tri2/baseline/**` (203 Markdown/JSON files): this is a full older application snapshot and its test fixtures, not a linked handover evidence packet. Copying it would duplicate application documentation and fixture material outside the requested evidence set. The exact source folder was available locally; none of its contents were modified.
- All application source worktrees and the non-documentation portions of the prior `/workspace/KPOne-handover/` checkout. Only its `docs/` archive was considered, with executable helpers excluded; no PHP/Vue/TypeScript app tree or dependency tree entered this documentation branch.
- Any screenshot larger than 1 MB, environment files, key/certificate files, and any file matching the credential/token patterns used in the pre-copy scan. No credential, private-key, token, or populated `.env` match was found among the included docs/evidence.

## Source and review caveat

Evidence records were created for synthetic development/test work. They are snapshots, not guarantees of current CI or merge state. Re-fetch current PR heads/status before release decisions. The open-PR table uses the freshest locally available list for #1150–#1219 plus the later captured list for #1220–#1251; the repository API did not provide a current listing in this task, so the handover identifies the snapshot basis and asks the reader to recheck.
