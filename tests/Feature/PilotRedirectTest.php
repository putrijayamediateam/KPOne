<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Feature\Visit\VisitTestCase;

class PilotRedirectTest extends VisitTestCase
{
    public function test_branch_switch_returns_only_to_a_validated_local_path_and_ignores_stale_previous_url(): void
    {
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $this->withSession(['_previous' => ['url' => 'https://unrelated.test/stale']])
            ->post(route('branch-context.store'), ['branch_id' => $this->branch->id, 'return_to' => '/registration?tab=waiting'])
            ->assertRedirect('/registration?tab=waiting');
        foreach ([null, 'https://external.test/', '//external.test/', '/%2fexternal.test', '/\\external.test', '/%0d%0aLocation:evil', '/a/../b', '/%252fexternal.test', 'registration', []] as $bad) {
            $this->post(route('branch-context.store'), ['branch_id' => $this->branch->id, 'return_to' => $bad])->assertRedirect(route('dashboard'));
        }
        $this->post(route('branch-context.store'), ['branch_id' => 'invalid', 'return_to' => '/queue'])->assertRedirect('/queue')->assertSessionHasErrors('branch_id');
    }

    public function test_refused_security_update_uses_its_known_route(): void
    {
        $actor = $this->actor('director');
        $this->selectBranch($actor);
        $this->withSession(['_previous' => ['url' => '/check-in/status']])->put(route('user-password.update'), [])->assertRedirect(route('security.edit'))->assertSessionHasErrors();
    }

    public function test_controllers_have_no_bare_return_back_with_an_empty_allowlist(): void
    {
        $allowlist = [];
        $violations = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Http/Controllers'))) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $tokens = array_values(array_filter(token_get_all(file_get_contents($file->getPathname())), fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
            foreach ($tokens as $i => $token) {
                if (is_array($token) && $token[0] === T_RETURN && is_array($tokens[$i + 1] ?? null)
                    && $tokens[$i + 1][0] === T_STRING && strtolower($tokens[$i + 1][1]) === 'back'
                    && ($tokens[$i + 2] ?? null) === '(' && ! in_array($file->getPathname(), $allowlist, true)) {
                    $violations[] = $file->getPathname().':'.$token[2];
                }
            }
        }
        $this->assertSame([], $violations, 'Use an explicit known route for success and refusal; bare return back() reintroduces stale SPA navigation.');
    }
}
