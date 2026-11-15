# Local AI model for KPOne: analysis

Status: **analysis only. It authorises nothing.** No code, no dependency, no change to `NoAiServiceGuardTest`, `AGENTS.md`
or `docs/BASELINE.md`. Written for the owner (request relayed by the coordinator, 2026-11-15). Plain English.

Today KPOne calls no AI service (owner decision 2026-11-05, contract section 7), and a test fails if code, config or
packages name an AI provider. This note asks one question: could KPOne call an **open model that runs on a computer in
the clinic network**, with no per-use fees and nothing sent over the internet?

Everything about prices and speeds below is a **rough estimate** from public community benchmarks, not a supplier quote.
Check the model licences on each model's own page before choosing one; this is not legal advice.

## 1. Models and software

| Option | What it is | Malay and English | Licence (verify on the model page) |
|---|---|---|---|
| **Qwen3** (Alibaba), 8B / 14B / 32B | General model, many sizes | Good English. Malay is usable at 14B and above, weaker at 8B. | Mostly reported as Apache 2.0 (free for clinic use); one source disagrees for some sizes, so check each |
| **Qwen-SEA-LION** (AI Singapore, built on Qwen3-32B) | Tuned for South-East Asian languages including Malay | Best-looking choice for Malay; ranked first among open models under 200B for the region (their claim) | Not stated in what I found; check the model card |
| **Sailor2** (8B and larger) | Multilingual, covers Malay | Best small (8B) Malay option by its authors' claim | Apache 2.0 (reported) |
| **Llama 3.x** (Meta) | General model | Fine English; Malay is average | Meta community licence: commercial use allowed, attribution and an acceptable-use policy to follow |
| **Gemma** (Google) | General model | Fine English; Malay is average | Google's own terms with a prohibited-use policy that must be passed on; the strictest to read |
| **Mistral** small models | General model | Weaker Malay | Varies by model; some are Apache 2.0 |

**Runtime** (the program that runs the model and offers a local web address):

- **Ollama**: easiest to install and update; a few commands; fine for a clinic. Recommended.
- **llama.cpp server**: leaner, more control, a little more work.
- **vLLM**: fast for many users at once on a GPU server; more than this needs.

**Malay quality has to be tested, not assumed.** Ask each candidate 30 real questions (Daily summary numbers, SOP and Malay
guide questions) and have a person score the answers. That test is Option A below.

## 2. Hardware

Speeds are generation speed for a 4-bit model at short text length, from public benchmarks (2024 to 2026): an 8B model
runs about 90 tokens a second on an RTX 3090 and about 145 on an RTX 4090; a 14B about 55 and 92; a 32B about 28 and 42; a
70B does not fit one 24 GB card. A token is roughly three quarters of a word, and about 10 tokens a second already
reads at a comfortable pace. CPU-only figures are the least reliable; treat them as 3 to 10 tokens a second for an 8B model on a
modern server with fast memory.

| Tier | Machine | Models it runs well | Speed (rough) | Rough cost (RM, to quote) | Power and noise |
|---|---|---|---|---|---|
| 0 | CPU only, an existing or new server with 32 to 64 GB RAM | 8B, slowly 14B | 3 to 10 tok/s | 0 to 4,000 | 50 to 150 W; quiet |
| 1 | Mini-PC or Mac mini type with 32 to 64 GB shared memory | 14B, 32B slowly | 10 to 25 tok/s | 6,000 to 12,000 | 30 to 80 W; near silent |
| 2 | Desktop with one 24 GB GPU (used 3090 or new 4090) | 8B to 32B | 30 to 140 tok/s | 8,000 to 16,000 | 350 to 450 W under load, 50 to 80 W idle; fans audible |
| 3 | 48 GB or two GPUs | 70B | about 18 tok/s | 25,000 and up | loud, hot |

Do not buy Tier 3. For these jobs (short summaries, drafts, answers from documents) Tier 1 or 2 is enough, and the work
can run **in the background** (the 07:00 and 14:00 runs, or a button that says "writing...") so nobody waits at a screen.

**Where it sits:** a **separate box** on the clinic's internal network, not on the KPOne server. KPOne is the system of record; a
GPU, its driver updates and its heat should not share a machine with it. The firewall allows only the KPOne server to reach
the model's port; the box has no route to the internet except a short, deliberate window to download or update a model.
**Open point:** this only works if KPOne itself runs inside the clinic network, or has a private link (VPN) to the box. KPOne's
production hosting is not decided (BASELINE section 6, item 1); if it ends up in a cloud, the model box must be reachable by
private link or this idea needs a different shape.

## 3. First three uses, none with patient or staff personal data

Each one is **text only**: the model cannot change any record, send anything or click anything.

| Use | Input | Output | Who sees it | Person's role |
|---|---|---|---|---|
| **1. Daily summary in plain words** | The same counts and RM totals the Daily summary page already holds (they already pass `AggregatePayloadGuard`) | A 3 to 5 sentence paragraph ("Cheras saw 88 patients; sales were up on last week...") placed above the numbers, labelled "Written by a local AI model. The numbers below are the record." | Whoever may already see that section (Director, CA Supervisor, Finance Officer, Administrator; each only their sections) | Reads it; it decides nothing. Every number in the text must appear in the input, or the paragraph is not shown. |
| **2. Drafting HR memos and notices** | Notes the HR Manager types (topic, date, what to say). No names of staff in the prompt; a screen refuses IC, phone and email patterns. | A draft memo in English or Malay | The HR Manager only, in the memo editor, once the HR memo slice exists | The HR Manager edits and saves it as an ordinary draft; sending stays a separate human action |
| **3. Answers from SOPs and the Malay guides** | The question, plus the few matching passages from published SOPs (HR-5c) and `docs/guides/ms/`, found by Postgres text search (no vector database) | A short answer that **quotes the passage and names the SOP**; "I could not find this" when nothing matches | Any signed-in staff | The staff member; the SOP page itself stays the source and the read-and-acknowledge step is unchanged |

## 4. What would change in KPOne (only if the owner decides to go ahead)

1. **Rule wording.** `AGENTS.md`, `docs/agents/CONTRACT.md` section 7 and `docs/BASELINE.md` change from "KPOne calls no AI service" to
   "KPOne may call one configured model at an **internal address** only; never an internet AI service; never with patient,
   clinical or staff personal data." The 2026-11-05 owner decision is replaced by a new one, in the owner's words.
2. **Guard test.** Keep every existing check (no provider names, no AI packages, no key settings). Add positive checks: one
   configured address; it must be a private or localhost address, validated when the app starts; no other class may use it
   (a source test, like the stock-writer boundary test); no AI package is added: plain Laravel HTTP calls to the runtime.
3. **One gateway class** (for example `LocalModelGateway` under `app/Domain/Agents`) is the only code that talks to the model.
   It builds prompts from **fixed templates**, passes the input through `AggregatePayloadGuard` and the identity-number,
   email and phone screens, and screens the output the same way.
4. **Settings.** `.env.example` placeholders only, off by default: `LOCAL_AI_ENABLED=false`, `LOCAL_AI_URL=`,
   `LOCAL_AI_MODEL=`, `LOCAL_AI_TIMEOUT_SECONDS=20`. No key in code or `.env`; if the internal gateway needs one it lives in the
   environment's secrets.
5. **When the model is down.** 3 second connect and about 20 second generate limits; after 3 failures the feature pauses for
   5 minutes. The pages simply show their normal numbers without the AI text. Nothing waits on it, because uses 1 and 2 run in
   the background or on a button.
6. **Audit.** Each call is an agent-run step (the registry already exists: `ai_agent_runs`, `ai_agent_run_steps`) holding the
   **prompt's SHA-256, model name, token counts and time taken, never the prompt or the answer text**. A generated paragraph is
   stored as an ordinary encrypted agent message, labelled as AI-written.
7. **Keeping personal data out.** The prompt is built only from aggregate sections and clinic-authored documents, never from
   `ai_read` or any patient, visit, staff or HR record. Free text typed by a person (use 2) is screened and refused if it
   looks like an identity number, phone or email; names cannot be detected reliably, so the HR screen tells people not to
   type names and the owner decides whether that is enough.

## 5. Risks

- **Wrong or invented answers**, worse in Malay on small models. Mitigation: a number check on use 1, quotes on use 3, a
  person edits use 2, and the 30-question test before anything is built.
- **Prompt injection from documents:** a PDF could contain instructions. The model has no tools and cannot act, output is
  text only, links are stripped, and SOPs are uploaded only by HR and the Director.
- **Upkeep:** someone must apply model and driver updates, watch disk (a model is about 5 to 40 GB), and replace a failed
  box. Name that person before buying.
- **Security:** a new machine on the network. Separate network segment, only KPOne may reach it, no inbound from outside.
- **PDPA:** with no patient or staff personal data the model sees nothing personal, and running locally means nothing leaves
  the clinic. If masked patient data is ever added later, that needs the PDPA review first; "local" reduces the risk but does not
  remove the security and retention duties.
- **Licence:** each model has its own terms; read the chosen one (attribution for Llama, the prohibited-use policy for Gemma).

## 6. Options and recommendation

- **Option A, test first (about 2 weeks, RM 0, nothing in KPOne).** Run Ollama with Qwen3 14B and a Malay-tuned model on a
  spare machine or a borrowed GPU PC. Score 30 real questions in English and Malay. This tells the owner whether the Malay is
  good enough before any money or code.
- **Option B, one small box and one use (about RM 6,000 to 12,000).** Tier 1 machine, a 14B model, and only **use 1** (the Daily
  summary paragraph). The smallest KPOne change: one gateway class, one setting, one test change.
- **Option C, GPU box and all three uses (about RM 8,000 to 16,000).** Tier 2, a 32B Malay-tuned model, uses 1 to 3, with the
  SOP text search added.

**Recommendation:** A first, then B only if A shows the Malay and the number check are good enough. Choose C only if the clinic
really wants all three uses every day. Do not build anything before the owner decides.

**What the owner must decide:**
1. Allow KPOne to call a local model at all (this replaces the 2026-11-05 decision).
2. Where KPOne will run (clinic network or cloud), because the model box must sit next to it.
3. Who owns upkeep of the box.
4. English, Malay or both for generated text.
5. Which option (A, B or C) and the budget.
6. Whether HR memo drafting is acceptable when staff names cannot be detected automatically.
7. A licence check of the chosen model, and a PDPA and legal review if the use ever widens.

## Sources (used for speeds, models and licences; all secondary, verify before buying)

- Community benchmark tables: [hardware-corner.net RTX 4090 benchmarks](https://www.hardware-corner.net/rtx-4090-llm-benchmarks/), [hardware-corner.net GPU ranking](https://www.hardware-corner.net/gpu-ranking-local-llm/), [Puget Systems on llama.cpp](https://www.pugetsystems.com/labs/articles/effects-of-cpu-speed-on-gpu-inference-in-llama-cpp/)
- Malay-capable models: [Sailor2](https://sail.sea.com/blog/articles/55), [Southeast Asia models overview](https://seasia.co/2026/09/13/who-has-built-a-language-model-of-their-own-in-southeast-asia)
- Licence guides (not official; read the model cards): [License Decoder](https://bestllmfor.com/guides/llm-license-commercial-use/), [open-weight licences guide](https://braindetox.kr/en/posts/open_weight_model_license_guide.html)
