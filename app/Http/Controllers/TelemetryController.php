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
    public function __construct(private ?\Hari\Registry $hari = null)
    {
    }

    public function show(Request $request): InertiaResponse
    {
        // These attributes are populated by AuthorizeGithubTasks after it
        // rechecks the session identity and installed repository grant.
        $access = $request->attributes->get('proofpatch_github_access');
        $authorized = [];
        if (is_array($access) && is_int($access['userId'] ?? null) && $access['userId'] > 0
            && is_array($access['repositories'] ?? null)) {
            foreach ($access['repositories'] as $repo => $allowed) {
                if ($allowed === true && is_string($repo)) {
                    $authorized[] = strtolower($repo);
                }
            }
        }

        return Inertia::render('Telemetry', [
            'telemetry' => Telemetry::collect(authorizedRepositories: $authorized, includeLedger: false, hari: $this->hari),
        ]);
    }
}
