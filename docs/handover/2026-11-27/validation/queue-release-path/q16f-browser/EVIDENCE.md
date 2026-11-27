# Q16f synthetic browser evidence

Own isolated DB kpone_q16f_release_preview_test; port3154. Original synthetic dump restored, current-main pending migration applied. No production or real records accessed.

The guarded fixture creates one fictional OTC patient/visit through existing services, enters the OTC queue, then performs 26 real room calls separated by controlled synthetic clock minutes. One real dispensary room is renamed through the existing versioned room administration service between calls. This yields26 historical labels,26 calls and25 recalls, without exceeding the physical-room cap. Read-only database-proof.json independently confirms26/25/26/1.

The initial attempt created many physical rooms, hit the existing maximum and rolled its outer transaction back. Counts for its reserved patient and room names were verified zero before the corrected one-shot attempt; failed marker retained. No application policy was weakened.

Browser results.json contains ten successful checks and captured GET requests. It proves25+1 paging, complete totals, unsaved input preservation, keyboard focus before pagination, persistent evidence/focus after simulated failed GET, explicit retry, no mutation request, no horizontal overflow and44px/36px pager targets. Twelve screenshots cover header and pager at390/768/1440 light/dark. Header capture explicitly scrolls to the summary, pager capture to Next rooms. The unchanged synthetic draft is never saved.

Initial harness attempts incorrectly assumed a textarea and then a shortened exact label. They timed out before paging; these are not product failures or passing evidence. Final script uses the actual full labelled Input. Screenshot framing was corrected from entire long details element to its summary; no source/DOM content was changed.
