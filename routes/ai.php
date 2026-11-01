<?php

use Laravel\Mcp\Facades\Mcp;

// Local stdio server only: same trust as the CLI, no network endpoint.
// A web transport would need Sanctum/Passport auth first; do not add Mcp::web without it.
Mcp::local('proofpatch', \ProofPatch\Mcp\Servers\ProofPatchServer::class);
