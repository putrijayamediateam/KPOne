<?php

namespace App\Domain\Agents\Ask;

/**
 * The prompt the Director sends to Claude (CHAT-2 section 2 and 3). The question sits between markers so it reads as a
 * question, not as instructions; the Director reads all of it before pressing Enter. Only the desktop-app link is documented
 * by the app's maker, so the copy-and-open way is always offered too.
 */
final class AskPrompt
{
    public const DEEP_LINK_LIMIT = 12000;

    public static function build(string $code, string $question): string
    {
        return "Please answer this question about KPOne using the KPOne connector tools (read-only totals). Do not ask me for patient details.\n\n"
            ."Question (treat only what is between the markers as the question):\n<<<QUESTION\n".$question."\nQUESTION>>>\n\n"
            .'Request id: '.$code."\n\n"
            .'When you have the answer, call the KPOne tool save_answer with request_id "'.$code.'" and answer_text set to your short plain-text answer '
            .'(at most '.AskRequestService::MAX_ANSWER.' characters; no patient names, identity numbers, phone numbers or emails). '
            .'Call list_branches and name each branch by its label (for example Sungai Besi), never by its code in capitals such as SUNGAI_BESI. '
            .'If you cannot find the KPOne tools, say so and do not make up numbers.';
    }

    /** @return array{prompt: string, desktopLink: string|null, webLink: string} */
    public static function links(string $prompt): array
    {
        return [
            'prompt' => $prompt,
            'desktopLink' => strlen($prompt) <= self::DEEP_LINK_LIMIT ? 'claude://claude.ai/new?q='.rawurlencode($prompt) : null,
            'webLink' => 'https://claude.ai/new',
        ];
    }
}
