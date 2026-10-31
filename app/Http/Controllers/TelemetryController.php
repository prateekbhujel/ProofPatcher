<?php

declare(strict_types=1);

namespace ProofPatch\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use ProofPatch\Support\Telemetry;

/** Local usage telemetry page. Read-only aggregation, no external calls. */
final class TelemetryController
{
    public function show(Request $request): InertiaResponse
    {
        return Inertia::render('Telemetry', [
            'telemetry' => Telemetry::collect(),
        ]);
    }
}
