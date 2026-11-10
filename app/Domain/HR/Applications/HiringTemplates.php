<?php

namespace App\Domain\HR\Applications;

use InvalidArgumentException;

/**
 * HR-17b fixed hiring messages (HR-PLAN 19.2; owner, 2026-11-10). English and Bahasa Melayu side by side; no free text
 * from any KPOne user beyond the position title HR entered, and never a rating, a call note, a reason for a decision
 * or anything the candidate sent. The reminder to the hiring mailbox names no candidate, only a short reference. The
 * Malay wording is a draft until the clinic checks it. The text is rebuilt from here whenever it is shown and is
 * never stored.
 */
final class HiringTemplates
{
    public const CLINIC = 'Klinik Putrijaya';

    /** @var array<string, array{label:string, subject:string, body:string, candidate_label?:string}> */
    public const TEMPLATES = [
        'received' => [
            'label' => 'Application received',
            'subject' => 'We received your application / Permohonan anda telah diterima',
            'body' => "Thank you for applying for {position} at {clinic}. We will look at your application and contact you. You can follow it with your private link below.\n\n"
                ."Terima kasih kerana memohon jawatan {position} di {clinic}. Kami akan menyemak permohonan anda dan menghubungi anda. Anda boleh mengikutinya melalui pautan peribadi anda di bawah.\n\n"
                .'Your private link / Pautan peribadi anda: {link}',
        ],
        'screening_passed' => [
            'label' => 'Invited to an interview',
            'subject' => 'Interview for {position} / Temu duga untuk {position}',
            'body' => "Thank you for speaking with us. We would like to invite you to an interview for {position}. Please choose three times that suit you on your private page.\n\n"
                .'Terima kasih kerana bercakap dengan kami. Kami ingin menjemput anda ke temu duga untuk jawatan {position}. Sila pilih tiga masa yang sesuai di halaman peribadi anda.',
        ],
        'not_taken_forward' => [
            'label' => 'Not taken forward',
            'subject' => 'Your application for {position} / Permohonan anda untuk {position}',
            'body' => "Thank you for your interest in {position}. We will not take your application forward this time. We wish you well.\n\n"
                .'Terima kasih atas minat anda terhadap jawatan {position}. Kami tidak akan meneruskan permohonan anda kali ini. Kami doakan yang terbaik untuk anda.',
        ],
        'auto_rejected' => [
            'label' => 'Closed without a decision',
            // What the candidate's own list says: the email never tells them nobody decided (Suresh, HR-17b).
            'candidate_label' => 'Not taken forward',
            'subject' => 'Your application for {position} / Permohonan anda untuk {position}',
            'body' => "Thank you for applying for {position}. We are not able to take your application forward this time. You are welcome to apply again.\n\n"
                .'Terima kasih kerana memohon jawatan {position}. Kami tidak dapat meneruskan permohonan anda kali ini. Anda dialu-alukan untuk memohon semula.',
        ],
        'withdrawn' => [
            'label' => 'Withdrawn',
            'subject' => 'Your application was withdrawn / Permohonan anda telah ditarik balik',
            'body' => "Your application for {position} has been withdrawn, as you asked. Thank you for your interest.\n\n"
                .'Permohonan anda untuk jawatan {position} telah ditarik balik seperti yang anda minta. Terima kasih atas minat anda.',
        ],
        'passed' => [
            'label' => 'Passed the interview',
            'subject' => 'Your interview for {position} / Temu duga anda untuk {position}',
            'body' => "Thank you for attending the interview for {position}. We are pleased to tell you that you passed. The clinic will contact you about the next steps.\n\n"
                .'Terima kasih kerana menghadiri temu duga untuk jawatan {position}. Dengan sukacitanya kami maklumkan bahawa anda lulus. Pihak klinik akan menghubungi anda tentang langkah seterusnya.',
        ],
        'failed' => [
            'label' => 'Did not pass the interview',
            'subject' => 'Your interview for {position} / Temu duga anda untuk {position}',
            'body' => "Thank you for attending the interview for {position}. We will not take your application further this time. We wish you well.\n\n"
                .'Terima kasih kerana menghadiri temu duga untuk jawatan {position}. Kami tidak akan meneruskan permohonan anda kali ini. Kami doakan yang terbaik untuk anda.',
        ],
        'interview_confirmed' => [
            'label' => 'Interview time confirmed',
            'subject' => 'Your interview on {date} / Temu duga anda pada {date}',
            'body' => "Your interview for {position} is on {date} at {time}, at {clinic} {branch}. If you cannot come, you can withdraw on your private page.\n\n"
                .'Temu duga anda untuk jawatan {position} adalah pada {date} jam {time}, di {clinic} {branch}. Jika anda tidak dapat hadir, anda boleh menarik balik permohonan di halaman peribadi anda.',
        ],
        'pick_again' => [
            'label' => 'Asked for new times',
            'subject' => 'Please choose new interview times / Sila pilih masa temu duga yang baharu',
            'body' => "The times you chose for {position} do not suit the clinic. Please choose three new times on your private page.\n\n"
                .'Masa yang anda pilih untuk jawatan {position} tidak sesuai dengan klinik. Sila pilih tiga masa baharu di halaman peribadi anda.',
        ],
        'interview_cancelled' => [
            'label' => 'Interview cancelled',
            'subject' => 'Your interview on {date} was cancelled / Temu duga anda pada {date} dibatalkan',
            'body' => "Your interview for {position} on {date} at {time} has been cancelled. Please choose three new times on your private page.\n\n"
                .'Temu duga anda untuk jawatan {position} pada {date} jam {time} telah dibatalkan. Sila pilih tiga masa baharu di halaman peribadi anda.',
        ],
        'pool_invited' => [
            'label' => 'Invited from the talent pool',
            'subject' => 'A new opening: {position} / Jawatan baharu: {position}',
            'body' => "You asked us to keep your details for future openings. We would like to consider you for {position} at {clinic}, using the answers and CV you sent before. We will call you first. You can follow this application, or withdraw it, with your new private link below.\n\n"
                ."Anda meminta kami menyimpan butiran anda untuk jawatan akan datang. Kami ingin mempertimbangkan anda untuk jawatan {position} di {clinic}, menggunakan jawapan dan CV yang anda hantar sebelum ini. Kami akan menghubungi anda dahulu. Anda boleh mengikuti permohonan ini, atau menariknya balik, melalui pautan peribadi baharu anda di bawah.\n\n"
                .'Your private link / Pautan peribadi anda: {link}',
        ],
        'auto_reject_reminder' => [
            'label' => 'Reminder',
            'subject' => 'Hiring: application {reference} closes at the next working day',
            'body' => 'Application {reference} for {position} is waiting at {stage}. If nobody decides today, KPOne closes it as Auto-rejected at the start of the next working day and tells the candidate. Open KPOne, then KPOne HR applications.',
        ],
    ];

    public const FOOTER = 'This is an automatic message from KPOne for Klinik Putrijaya. Mesej ini dihantar secara automatik.';

    /** What a candidate sees in place of the private link when an email is shown again (the token is never kept). */
    public const LINK_SHOWN_ONCE = 'shown once, when you applied / ditunjukkan sekali, semasa anda memohon';

    /** HR-17e: the same for an invitation from the talent pool, whose link went only in that message. */
    public const LINK_IN_INVITATION = 'sent once, with the invitation / dihantar sekali, bersama jemputan';

    /** @var array<string, string> each placeholder and the only shape its value may take */
    public const PLACEHOLDERS = [
        'position' => '/\A[^\r\n<>{}]{3,80}\z/u',
        'clinic' => '/\AKlinik Putrijaya\z/',
        'link' => '/\A(https?:\/\/[^\s<>]{1,200}#[A-Za-z0-9_-]{43}|shown once, when you applied \/ ditunjukkan sekali, semasa anda memohon|sent once, with the invitation \/ dihantar sekali, bersama jemputan)\z/',
        'reference' => '/\A[0-9A-F]{8}\z/',
        'stage' => '/\A(New|Phone screening)\z/',
        'date' => '/\A[A-Z][a-z]{2} \d{1,2} [A-Z][a-z]{2} \d{4}\z/', // Tue 17 Nov 2026
        'time' => '/\A\d{1,2}:00 (AM|PM)\z/',
        'branch' => '/\A[^\r\n<>{}]{2,60}\z/u',
    ];

    public static function label(string $template): string
    {
        return (self::TEMPLATES[$template] ?? throw new InvalidArgumentException('Unknown hiring template.'))['label'];
    }

    /** The label on the candidate's own page; the same as HR's except where HR's words say more than the email. */
    public static function candidateLabel(string $template): string
    {
        $fixed = self::TEMPLATES[$template] ?? throw new InvalidArgumentException('Unknown hiring template.');

        return $fixed['candidate_label'] ?? $fixed['label'];
    }

    /**
     * @param  array<string, string>  $params
     * @return array{subject:string, body:string}
     */
    public static function render(string $template, array $params): array
    {
        $fixed = self::TEMPLATES[$template] ?? throw new InvalidArgumentException('Unknown hiring template.');
        $params = [...(str_contains($fixed['body'], '{clinic}') ? ['clinic' => self::CLINIC] : []), ...$params];
        $needed = self::placeholders($template);
        if (array_diff($needed, array_keys($params)) !== [] || array_diff(array_keys($params), $needed) !== []) {
            throw new InvalidArgumentException('Hiring placeholders do not match the template.');
        }
        foreach ($params as $key => $value) {
            if (preg_match(self::PLACEHOLDERS[$key], $value) !== 1) {
                throw new InvalidArgumentException("Hiring placeholder {$key} has a value it may not hold.");
            }
        }
        $swap = fn (string $text): string => strtr($text, array_combine(array_map(fn (string $k): string => '{'.$k.'}', array_keys($params)), array_values($params)));

        return ['subject' => $swap($fixed['subject']), 'body' => $swap($fixed['body'])."\n\n".self::FOOTER];
    }

    /** @return list<string> */
    public static function placeholders(string $template): array
    {
        $fixed = self::TEMPLATES[$template] ?? throw new InvalidArgumentException('Unknown hiring template.');
        preg_match_all('/\{([a-z]+)\}/', $fixed['subject'].' '.$fixed['body'], $m);

        return array_values(array_unique($m[1]));
    }
}
