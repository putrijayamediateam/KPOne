<?php

namespace App\Domain\Clinical\Documents;

use Illuminate\Support\Facades\Crypt;

final class DocumentEvidenceCodec
{
    /** @param array<string, mixed> $snapshot
     * @return array{encrypted_snapshot: string, ciphertext_sha256: string}
     */
    public function encode(array $snapshot): array
    {
        $cipher = Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR));

        return ['encrypted_snapshot' => $cipher, 'ciphertext_sha256' => hash('sha256', $cipher)];
    }

    /** @return array<string, mixed> */
    public function decode(string $cipher, string $hash): array
    {
        abort_unless(hash_equals($hash, hash('sha256', $cipher)), 500, 'Document evidence integrity failure.');

        return json_decode(Crypt::decryptString($cipher), true, flags: JSON_THROW_ON_ERROR);
    }
}
