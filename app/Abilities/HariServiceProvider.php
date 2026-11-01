<?php

declare(strict_types=1);

namespace ProofPatch\Abilities;

use Hari\Hari;
use Illuminate\Support\ServiceProvider;

/** Boots the global hari registry once, then freezes it. */
final class HariServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Boot-once: the same process may rebuild the app (console tests do).
        if (Hari::registry()->isFrozen()) {
            return;
        }
        HariBoot::boot(Hari::registry());
        Hari::registry()->freeze();
    }
}
