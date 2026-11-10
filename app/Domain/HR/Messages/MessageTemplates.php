<?php

namespace App\Domain\HR\Messages;

use InvalidArgumentException;

/**
 * HR-14 fixed message templates (HR-PLAN 15.3; owner, 2026-11-10). No free text from any KPOne user, and never a
 * patient, a clinical word, an amount of money, a leave type or reason, or an identity or bank value: each placeholder
 * takes only a date, a month, a claim number or a decision word, checked here before anything is sent. The template
 * lint test reads this file.
 */
final class MessageTemplates
{
    /** @var array<string, array{subject:string, body:string}> */
    public const TEMPLATES = [
        'leave_decided' => [
            'subject' => 'Your leave request was {decision}',
            'body' => 'Your leave request from {from} to {to} was {decision} in KPOne.',
        ],
        'roster_ready' => [
            'subject' => 'Your roster is ready',
            'body' => 'Your roster for the week of {week} is ready in KPOne.',
        ],
        'payslip_ready' => [
            'subject' => 'Your payslip is ready',
            'body' => 'Your payslip for {month} is ready in KPOne.',
        ],
        'claim_decided' => [
            'subject' => 'Your staff claim was {decision}',
            'body' => 'Your claim {claim} was {decision} in KPOne.',
        ],
    ];

    public const FOOTER = 'This is an automatic message from KPOne. To stop these messages, open KPOne, then My messages.';

    /** @var array<string, string> each placeholder and the only shape its value may take */
    public const PLACEHOLDERS = [
        'from' => '/\A\d{1,2} [A-Z][a-z]{2} \d{4}\z/', // 8 Mar 2026
        'to' => '/\A\d{1,2} [A-Z][a-z]{2} \d{4}\z/',
        'week' => '/\A\d{1,2} [A-Z][a-z]{2} \d{4}\z/',
        'month' => '/\A(January|February|March|April|May|June|July|August|September|October|November|December) \d{4}\z/',
        'claim' => '/\ASC-\d{6}\z/',
        'decision' => '/\A(approved|rejected|recorded as paid)\z/',
    ];

    /**
     * @param  array<string, string>  $params
     * @return array{subject:string, body:string}
     */
    public static function render(string $template, array $params): array
    {
        $fixed = self::TEMPLATES[$template] ?? throw new InvalidArgumentException('Unknown message template.');
        $needed = self::placeholders($template);
        if (array_diff($needed, array_keys($params)) !== [] || array_diff(array_keys($params), $needed) !== []) {
            throw new InvalidArgumentException('Message placeholders do not match the template.');
        }
        foreach ($params as $key => $value) {
            if (preg_match(self::PLACEHOLDERS[$key], $value) !== 1) {
                throw new InvalidArgumentException("Message placeholder {$key} has a value it may not hold.");
            }
        }
        $swap = fn (string $text): string => strtr($text, array_combine(array_map(fn (string $k): string => '{'.$k.'}', array_keys($params)), array_values($params)));

        return ['subject' => $swap($fixed['subject']), 'body' => $swap($fixed['body'])."\n\n".self::FOOTER];
    }

    /** @return list<string> the placeholders a template uses, in order of first use */
    public static function placeholders(string $template): array
    {
        $fixed = self::TEMPLATES[$template] ?? throw new InvalidArgumentException('Unknown message template.');
        preg_match_all('/\{([a-z]+)\}/', $fixed['subject'].' '.$fixed['body'], $m);

        return array_values(array_unique($m[1]));
    }
}
