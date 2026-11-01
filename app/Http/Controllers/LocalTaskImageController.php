<?php

declare(strict_types=1);

namespace ProofPatch\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use ProofPatch\Models\LocalTaskCard;
use Symfony\Component\HttpFoundation\Response;

/** Stores one private image with a task; images are not sent to a model. */
final class LocalTaskImageController
{
    public function store(Request $request, int $cardId): RedirectResponse
    {
        $card = $this->authorizedCard($request, $cardId);
        if ($card === null) {
            return redirect('/issues')->with('error', 'Task not found or access is unavailable.');
        }
        try {
            ['bytes' => $bytes, 'mime' => $mime] = \ProofPatch\Support\TaskImageValidation::validateUpload($request->file('image'));
        } catch (\InvalidArgumentException $error) {
            return redirect('/issues')->with('error', $error->getMessage());
        }

        // Do not replace an existing image or erase it on concurrent requests.
        $updated = LocalTaskCard::whereKey($card->id)->whereNull('image_bytes')->update([
            'image_bytes' => $bytes,
            'image_mime' => $mime,
            'updated_at' => now(),
        ]);
        if ($updated !== 1) {
            return redirect('/issues')->with('error', 'This task already has an image.');
        }
        return redirect('/issues')->with('success', 'Image saved.');
    }

    public function show(Request $request, int $cardId): Response
    {
        $card = $this->authorizedCard($request, $cardId);
        if ($card === null || !is_string($card->image_bytes)
            || !in_array($card->image_mime, ['image/png', 'image/jpeg'], true)) {
            return response('Not found.', 404)->header('Cache-Control', 'no-store');
        }
        return response($card->image_bytes, 200, [
            'Content-Type' => $card->image_mime,
            'Content-Disposition' => 'inline',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function authorizedCard(Request $request, int $cardId): ?LocalTaskCard
    {
        $access = $request->attributes->get('proofpatch_github_access');
        $userId = is_array($access) ? ($access['userId'] ?? null) : null;
        $repositories = is_array($access) ? ($access['repositories'] ?? null) : null;
        if (!is_int($userId) || $userId <= 0 || !is_array($repositories) || $cardId < 1) {
            return null;
        }
        $card = LocalTaskCard::whereKey($cardId)->where('github_user_id', $userId)->first();
        return $card !== null && isset($repositories[strtolower($card->repository)]) ? $card : null;
    }
}
