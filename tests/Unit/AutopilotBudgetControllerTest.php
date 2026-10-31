<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\TestCase;
use ProofPatch\Http\Controllers\DashboardController;
use ProofPatch\Models\AutopilotBudget;
use ProofPatch\Tests\Support\InteractsWithIsolatedDatabase;

final class AutopilotBudgetControllerTest extends TestCase
{
    use InteractsWithIsolatedDatabase;

    protected function setUp(): void
    {
        $this->setupIsolatedDatabase();
        $this->bootstrapAndMigrateIsolatedDatabase();
    }

    protected function tearDown(): void
    {
        $this->teardownIsolatedDatabase();
        @restore_error_handler();
        @restore_exception_handler();
    }

    private function post(array $input): \Illuminate\Http\RedirectResponse
    {
        $request = Request::create('/settings/autopilot-budget', 'POST', $input);
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(10)));

        return (new DashboardController())->saveAutopilotBudget($request);
    }

    public function testValidCapsAreSaved(): void
    {
        $response = $this->post(['maxAttempts' => '25', 'maxDollars' => '2.50']);

        $this->assertSame(302, $response->getStatusCode());
        $row = AutopilotBudget::current();
        $this->assertNotNull($row);
        $this->assertSame(25, $row->max_attempts);
        $this->assertSame(2500000, $row->max_reserved_micros);
    }

    public function testBlankInputsClearCaps(): void
    {
        AutopilotBudget::saveCaps(25, 2500000);

        $response = $this->post(['maxAttempts' => '', 'maxDollars' => '']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull(AutopilotBudget::current());
    }

    public function testInvalidAttemptCapIsRejected(): void
    {
        $this->post(['maxAttempts' => '0', 'maxDollars' => '']);

        $this->assertNull(AutopilotBudget::current());
    }

    public function testInvalidSpendCapIsRejected(): void
    {
        $this->post(['maxAttempts' => '', 'maxDollars' => '-5']);

        $this->assertNull(AutopilotBudget::current());
    }

    public function testSettingsExposesCaps(): void
    {
        AutopilotBudget::saveCaps(25, 2500000);

        $request = Request::create('/settings', 'GET');
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(10)));
        $props = (new DashboardController())->settings($request)
            ->toResponse($request)->getOriginalContent()->getData()['page']['props'];

        $this->assertSame(
            ['maxAttempts' => 25, 'maxReservedDollars' => 2.5],
            $props['autopilotBudget']
        );
    }
}
