<?php

declare(strict_types=1);

namespace ProofPatch\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $notifications = [];
        if ($request->hasSession()) {
            $notifications = $request->session()->get('notifications', []);
        } elseif (isset($_SESSION['notifications']) && is_array($_SESSION['notifications'])) {
            $notifications = $_SESSION['notifications'];
        }

        return array_merge(parent::share($request), [
            // Desktop app windows embed pages in iframes (?embed=1): the shell
            // drops its own chrome there so windows never nest menu bars.
            'embed' => $request->query('embed') === '1',
            'csrf_token' => csrf_token(),
            'notifications' => $notifications,
            'error' => fn () => $request->session()->get('error'),
            'flash' => [
                'error' => fn () => $request->session()->get('error'),
                'success' => fn () => $request->session()->get('success'),
                'github_status' => fn () => $request->session()->get('github_status'),
                'github_error' => fn () => $request->session()->get('github_error'),
            ],
        ]);
    }
}
