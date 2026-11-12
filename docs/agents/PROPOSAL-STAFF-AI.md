# Proposal: staff ask an AI about their own department

Status: **proposal only. Nothing is built and nothing is authorised.** Written by the Agent platform team at the owner's request (relayed 2026-11-12).
It would change the owner's 2026-11-05 decision "KPOne calls no AI service" only if the owner later approves it in writing.

## 1. The question

Can staff ask an AI about their own department inside KPOne, without KPOne paying for an API? The owner's idea: connect the owner's Claude account to the system, so staff questions are answered from "here" and only plan usage is consumed, with no API usage credit.

## 2. Plain answer on the subscription idea

- A personal Claude subscription (claude.ai, Claude Code) is for the account holder's own use.
- Anthropic's terms do not allow an app to serve other people's requests through a subscription login, including through the Agent SDK. Apps that serve other users must use API-key authentication.
- So staff questions **cannot** be routed through the owner's plan. This is a terms question, not a technical one, and the owner should confirm it against Anthropic's current terms before relying on this summary.
- The owner's own **Ask KPOne** (Option A in `docs/BASELINE.md`) is unaffected. It is the owner's personal use from Claude Code and stays as decided.
- The cheapest compliant path is the **Claude API, billed per use, with no monthly fee**. For a clinic this size the cost is small (section 3).

## 3. Cost options

Assumption: 30 staff, 10 questions a day, about 9,000 questions a month, roughly 3,000 input and 500 output tokens each. Prices below were given to us and **must be checked against Anthropic's current price list before any decision**.

| Option | Input price | Output price | Cost per question | Cost per month (9,000) |
|---|---|---|---|---|
| 1. Haiku 5.5 | $0.10 / MTok | $0.50 / MTok | 3,000 × 0.10 / 1M = $0.00030 in, 500 × 0.50 / 1M = $0.00025 out, total about **$0.0006** | about **$5** |
| 2. Sonnet 5.5 | $2 / MTok | $10 / MTok | 3,000 × 2 / 1M = $0.0060 in, 500 × 10 / 1M = $0.0050 out, total about **$0.011** | about **$100** |
| 3. Mix | Haiku by default, Sonnet only for longer report explanations | | between the two | **$5 to $100**, about **$15 to $30** if one question in ten uses Sonnet |

- **Prompt caching** lowers the input side. The fixed part of each prompt (instructions and data-source descriptions) is the same for every question, so it can be cached and read at a fraction of the normal input price. The saving is real but depends on how much of the 3,000 tokens is fixed; we will measure it in the pilot rather than promise a number.
- **Hard monthly cap:** set a spend limit in the Anthropic console (for example $20 a month for the pilot) **and** a counter inside KPOne that stops answering when the month's estimated spend reaches the cap. Staff see "The assistant is paused until next month", never an error.

## 4. Design, safe first

1. **Same data as the space pages.** The AI may only use the read-only, permission-checked sources the department pages already use (`AgentToolGateway`, `AggregatePayloadGuard`). It sees only what the asking person's role and branch already allow.
2. **Aggregates only in phase 1.** No patient-identifiable data and no clinical text is sent to the AI. `AggregatePayloadGuard` already refuses names, IC, phone and email-like values.
3. **Read-only.** It explains and answers. It never changes data and never proposes a state change that executes by itself.
4. **Audited.** Every question and answer is logged without patient data (who, when, department, source names, token counts, cost), with a **per-user daily limit** (for example 30 questions).
5. **Explicit AI label.** Answers are marked as AI-generated, with the figures and the data source named, so staff can check them.
6. **Kill switch.** The Director can turn the feature off at once. A missing API key means the feature is simply absent.
7. **No data leaves except the question context.** Prompts contain the question and the allowed aggregate figures only.

## 5. PDPA and legal

- A privacy and legal review is needed **before any patient data** goes near the AI. Phase 1 avoids it by design, but aggregate figures about a small branch can still be sensitive.
- Check Anthropic's data retention terms for API use, any zero-retention arrangement, and where data is processed (data residency).
- The staff privacy notice must say that questions and the allowed figures are processed by an external AI service.
- Review again before any phase that adds patient-level data. That phase is out of scope here.

## 6. Pilot (phase 1)

One department, aggregates only, a short list of named staff, Haiku by default, a low cap, and a review of the audit log after two weeks. Suggested first department: one that already has aggregate pages (for example Inventory or Billing). The owner chooses.

## 7. Decisions needed from the owner

1. Allow an external AI service at all (this reverses the 2026-11-05 decision for this feature only)?
2. The monthly spend cap.
3. The model: Haiku, Sonnet, or the mix.
4. Which roles and which department go first.
5. Who holds the API key (this key needs its own named holder) and where it is stored (server environment only, never in the repository).
6. Who reviews the PDPA and legal position, and when.

## 8. Not in this proposal

Patient data, voice input, actions that change data, other languages beyond what the pages already show, and any use of the owner's personal subscription for staff.
