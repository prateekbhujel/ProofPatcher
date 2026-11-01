<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ProofPatch\Http\Controllers\AutomaticInlineSettingController;
use ProofPatch\Models\AutomaticInlineSetting;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;

final class AutomaticInlineSettingControllerTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    protected function setUp(): void { $this->setupIsolatedDatabase(); $this->bootstrapAndMigrateIsolatedDatabase(); }
    protected function tearDown(): void { $this->teardownIsolatedDatabase(); @restore_error_handler(); @restore_exception_handler(); }

    private function request(array $data, ?int $userId = 7, string $login = 'owner', bool $private = false, bool $granted = true): Request
    {
        $request = Request::create('/repositories/auto-comments', 'POST', $data);
        $session = app('session')->driver('array');
        $session->put('github_auth', ['token' => 'fake', 'user' => ['id' => 7, 'login' => $login],
            'repositories' => [['fullName' => 'owner/repo', 'isPrivate' => $private]]]);
        $request->setLaravelSession($session);
        $request->attributes->set('proofpatch_github_access', ['userId' => $userId, 'repositories' => $granted ? ['owner/repo' => true] : []]);
        return $request;
    }

    public function testOnlyVerifiedOwnerCanOptInAndCanOptOut(): void
    {
        $controller = new AutomaticInlineSettingController();
        self::assertFalse(AutomaticInlineSetting::enabled(7, 'owner/repo'));
        self::assertSame(401, $controller->toggle($this->request(['repository' => 'owner/repo', 'enabled' => true], null))->getStatusCode());
        self::assertSame(403, $controller->toggle($this->request(['repository' => 'owner/repo', 'enabled' => true], 7, 'other'))->getStatusCode());
        self::assertSame(403, $controller->toggle($this->request(['repository' => 'owner/repo', 'enabled' => true], 7, 'owner', false, false))->getStatusCode());
        self::assertSame(422, $controller->toggle($this->request(['repository' => 'owner/repo', 'enabled' => true], 7, 'owner', true))->getStatusCode());
        self::assertSame(422, $controller->toggle($this->request(['repository' => 'owner/repo', 'enabled' => 'true']))->getStatusCode());
        self::assertSame(200, $controller->toggle($this->request(['repository' => 'OWNER/Repo', 'enabled' => true]))->getStatusCode());
        self::assertTrue(AutomaticInlineSetting::enabled(7, 'owner/repo'));
        self::assertFalse(AutomaticInlineSetting::enabled(8, 'owner/repo'));
        self::assertSame(200, $controller->toggle($this->request(['repository' => 'owner/repo', 'enabled' => false]))->getStatusCode());
        self::assertFalse(AutomaticInlineSetting::enabled(7, 'owner/repo'));
    }

    public function testRevocationClearsOnlyThatUsersSettings(): void
    {
        AutomaticInlineSetting::setForOwner(7, 'owner/repo', true);
        AutomaticInlineSetting::setForOwner(8, 'other/repo', true);
        AutomaticInlineSetting::revokeAllForUser(7);
        self::assertFalse(AutomaticInlineSetting::enabled(7, 'owner/repo'));
        self::assertTrue(AutomaticInlineSetting::enabled(8, 'other/repo'));
    }

    public function testLostGrantIsNotRestoredByRegrant(): void
    {
        AutomaticInlineSetting::setForOwner(7, 'owner/repo', true);
        AutomaticInlineSetting::setForOwner(7, 'owner/kept', true);
        AutomaticInlineSetting::setForOwner(8, 'other/repo', true);
        AutomaticInlineSetting::retainOnlyAuthorizedPublic(7, [['fullName' => 'owner/kept', 'isPrivate' => false]]);
        self::assertFalse(AutomaticInlineSetting::enabled(7, 'owner/repo'));
        self::assertTrue(AutomaticInlineSetting::enabled(7, 'owner/kept'));
        self::assertTrue(AutomaticInlineSetting::enabled(8, 'other/repo'));
        AutomaticInlineSetting::retainOnlyAuthorizedPublic(7, [
            ['fullName' => 'owner/kept', 'isPrivate' => false],
            ['fullName' => 'owner/repo', 'isPrivate' => false],
        ]);
        self::assertFalse(AutomaticInlineSetting::enabled(7, 'owner/repo'));
        AutomaticInlineSetting::retainOnlyAuthorizedPublic(7, [['fullName' => 'owner/kept', 'isPrivate' => true]]);
        self::assertFalse(AutomaticInlineSetting::enabled(7, 'owner/kept'));
    }
}
