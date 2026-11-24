<?php

/*
 | The AI chatbox provider settings (docs/agents/AI-CHATBOX-DESIGN.md sections 5 and 10). This file and
 | app/Domain/Agents/Chat/Providers are the only places allowed to name the provider (NoAiServiceGuardTest).
 | The key is read from the server's environment secrets (AI_CHAT_API_KEY) and is never written in code, a committed file or chat.
 */
return [
    'api_key' => env('AI_CHAT_API_KEY'),
    'endpoint' => env('AI_CHAT_ENDPOINT', 'https://api.anthropic.com/v1/messages'),
    'api_version' => '2023-06-01',
    'model' => env('AI_CHAT_MODEL', 'claude-haiku-5-5'),
    'max_output_tokens' => 1024,
    'timeout_seconds' => 40,

    // The owner's monthly cap (2026-11-24): RM 50, in sen. Spend is estimated from the provider's reported token use.
    'monthly_cap_sen' => (int) env('AI_CHAT_MONTHLY_CAP_SEN', 5000),
    // Estimated price per million tokens in US dollars, and the exchange rate to ringgit. To be confirmed with the real price list.
    'price_in_per_million_usd' => (float) env('AI_CHAT_PRICE_IN_USD', 1.0),
    'price_out_per_million_usd' => (float) env('AI_CHAT_PRICE_OUT_USD', 5.0),
    'usd_to_myr' => (float) env('AI_CHAT_USD_TO_MYR', 4.8),

    'messages_per_minute' => 10,
    'messages_per_day' => 30,
    'max_prompt_chars' => 1000,
    'max_tool_calls' => 6,
    'max_answer_chars' => 4000,
    'history_days' => 90,
    'history_context_messages' => 8,
];
