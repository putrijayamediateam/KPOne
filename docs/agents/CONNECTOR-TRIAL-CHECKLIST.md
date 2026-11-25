# KPOne connector: trial-server checklist

For the person who manages the trial server environment. Do these in order. Nothing here touches a server with real patient
data: **the connector stays off there until the owner gives a separate written OK at switch-on, naming the server and the date.** (The PDPA and lawyer review itself is approved, owner-confirmed, verbal, 25 Nov 2026.)

## Before switching on

**kpjaya.com** (Puchong test from 30 Nov 2026: the clinic's real inventory and prices, fictional patients): the connector stays
**off** (owner decision, 25 Nov 2026). The switch-off steps for 29 Nov were sent to the coordinator.

1. Either the server holds **fictional data only**, or the owner has given the **separate written OK** for this server (naming the server and the date).
2. `php artisan migrate` has run (adds the permission and the OAuth tables).
3. The OAuth signing keys exist: `php artisan passport:keys`, or `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` set in the
   environment (never in a file that is committed). The key files must not be readable by other users (mode 0600).
4. The server is reachable over `https` at the address the owner will type into Claude, and its trusted-proxy setting is
   correct so KPOne sees `https`.
5. `MCP_REDIRECT_DOMAINS` is left unset (defaults to the Claude app's own addresses) unless the owner's Claude plan needs
   another one; each entry ends with a slash.
6. The Director's account is active and holds `ai_connector.use.organisation` (given to the Director role only).

## Switch on

7. Set `AGENTS_CONNECTOR_ENABLED=true` in the server environment and reload the application configuration.
8. From any computer, `https://<server>/.well-known/oauth-authorization-server` and `https://<server>/mcp` should no longer
   answer "not found" (`/mcp` without a token answers "unauthorised").

## Try it (with the owner)

9. The owner adds the connector in Claude (see the owner guide) and presses Allow on the KPOne screen.
10. The owner asks three questions: branches ("which branches can I ask about?"), clinic day, and money day. Compare each
    with the KPOne screen it comes from.
11. In KPOne, open **AI agents**: the "Claude connector (AI agent)" shows recent runs. Open the audit log: one
    `ai_connector.tool_called` row per question, with no figures.
12. Press **Disconnect** on **Connected apps**; a new question in Claude must fail.

## Switch off and revoke

13. To stop at once: set `AGENTS_CONNECTOR_ENABLED=false` and reload configuration (every connector address then answers
    "not found").
14. To end connections but keep the connector on: `php artisan agents:connector-revoke` (everyone) or
    `--user=<email>` (one person).

## Record

15. Write down: who switched it on, the date, which server, and either that the data is fictional or the reference to the owner's written OK. The team needs a name to
    record, not a login.
