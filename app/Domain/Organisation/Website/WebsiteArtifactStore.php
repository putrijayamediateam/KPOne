<?php

namespace App\Domain\Organisation\Website;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WebsiteArtifactStore
{
    /** @param array<string,array{bytes:string,mime:string}> $files
     * @return list<array{path:string,mime:string,bytes:int,sha256:string}> */
    public static function manifest(array $files): array
    {
        ksort($files);
        $out = [];
        foreach ($files as $path => $file) {
            if (! WebsiteStaticRenderer::safePath($path)) {
                throw new WebsiteArtifactFailure('paths');
            }
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $mime = match ($extension) {
                'html' => 'text/html','css' => 'text/css','js' => 'text/javascript','json' => 'application/json','xml' => 'application/xml','txt','' => 'text/plain','png' => 'image/png','jpg' => 'image/jpeg','webp' => 'image/webp',default => ''
            };
            if ($file['mime'] !== $mime) {
                throw new WebsiteArtifactFailure('integrity');
            }
            $out[] = ['path' => $path, 'mime' => $mime, 'bytes' => strlen($file['bytes']), 'sha256' => hash('sha256', $file['bytes'])];
        }

        return $out;
    }

    public function stage(string $token, string $path, string $bytes): string
    {
        $key = $this->key($token, $path);
        if (! Storage::disk('website_artifacts')->put($key, Crypt::encryptString(base64_encode($bytes)))) {
            throw new WebsiteArtifactFailure('storage');
        }
        if ($this->generated($key) !== $bytes) {
            throw new WebsiteArtifactFailure('storage');
        }

        return $key;
    }

    public function discard(string $token): void
    {
        if (! preg_match('/\A[a-f0-9-]{36}\z/', $token)) {
            throw new WebsiteArtifactFailure('paths');
        }
        Storage::disk('website_artifacts')->deleteDirectory('attempts/'.$token);
    }

    public function key(string $token, string $path): string
    {
        if (! preg_match('/\A[a-f0-9-]{36}\z/', $token) || ! WebsiteStaticRenderer::safePath($path)) {
            throw new WebsiteArtifactFailure('paths');
        }

        return 'attempts/'.$token.'/'.hash('sha256', $path).'.enc';
    }

    private function generated(string $key): string
    {
        try {
            if (! preg_match('~\Aattempts/[a-f0-9-]{36}/[a-f0-9]{64}\.enc\z~', $key)) {
                throw new WebsiteArtifactFailure('paths');
            }
            $bytes = base64_decode(Crypt::decryptString(Storage::disk('website_artifacts')->get($key)), true);
            if (! is_string($bytes)) {
                throw new WebsiteArtifactFailure('integrity');
            }

            return $bytes;
        } catch (\Throwable) {
            throw new WebsiteArtifactFailure('integrity');
        }
    }

    /** Internal materialisation only: no HTTP/file-serving entry point exists in this slice.
     * @return array<string,array{bytes:string,mime:string}> */
    public function verify(int $organisation, int $id): array
    {
        $a = DB::table('website_artifacts')->where('organisation_id', $organisation)->where('id', $id)->first();
        abort_unless($a !== null, 404);
        $index = DB::table('website_artifact_files')->where('artifact_id', $a->id)->orderBy('path')->get();
        $files = [];
        foreach ($index as $f) {
            if ($f->storage_kind === 'generated') {
                $bytes = $this->generated($f->storage_key);
            } else {
                $r = $f->storage_kind === 'release_media'
                    ? DB::table('website_release_media')->where('id', $f->release_media_id)->where('release_id', $a->release_id)->first()
                    : DB::table('website_output_plan_assets')->where('id', $f->plan_asset_id)->where('plan_id', $a->plan_id)->first();
                if (! $r) {
                    throw new WebsiteArtifactFailure('integrity');
                }
                $bytes = WebsiteArtifactSource::decodeMedia($r)['bytes'];
            }
            if (strlen($bytes) !== $f->bytes || hash('sha256', $bytes) !== $f->sha256) {
                throw new WebsiteArtifactFailure('integrity');
            }
            $files[$f->path] = ['bytes' => $bytes, 'mime' => $f->mime];
        }
        $expectedHeader = array_intersect_key((array) $a, array_flip(['release_number', 'release_manifest_hash', 'plan_number', 'plan_hash', 'renderer_version', 'renderer_digest', 'profile_id', 'profile_digest', 'file_count', 'total_bytes', 'file_manifest_hash']));
        $manifest = WebsiteReleaseJson::encode(self::manifest($files));
        $identity = json_decode($a->identity_manifest, true, 512, JSON_THROW_ON_ERROR);
        if ($manifest !== $a->file_manifest || hash('sha256', $manifest) !== $a->file_manifest_hash || hash('sha256', $a->identity_manifest) !== $a->artifact_hash || WebsiteReleaseJson::encode($identity) !== $a->identity_manifest || WebsiteReleaseJson::encode($identity['files']) !== $manifest || WebsiteReleaseJson::encode($identity['header']) !== WebsiteReleaseJson::encode($expectedHeader) || count($files) !== $a->file_count || array_sum(array_column(self::manifest($files), 'bytes')) !== $a->total_bytes) {
            throw new WebsiteArtifactFailure('integrity');
        }

        return $files;
    }
}
