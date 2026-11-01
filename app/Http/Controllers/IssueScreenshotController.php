<?php

declare(strict_types=1);

namespace ProofPatch\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use ProofPatch\Models\WorkbenchTask;
use ProofPatch\Support\TaskImageValidation;
use Symfony\Component\HttpFoundation\Response;

/** Stores one private screenshot with the session's workbench task; images are not sent to a model. */
final class IssueScreenshotController
{
    public function store(Request $request): RedirectResponse
    {
        $task = $this->authorizedTask($request);
        if ($task === null) {
            return redirect('/issues')->with('error', 'Task not found or access is unavailable.');
        }
        try {
            ['bytes' => $bytes, 'mime' => $mime] = TaskImageValidation::validateUpload($request->file('image'));
        } catch (\InvalidArgumentException $error) {
            return redirect('/issues')->with('error', $error->getMessage());
        }

        // Do not replace an existing image or erase it on concurrent requests.
        $updated = WorkbenchTask::whereKey($task->id)->whereNull('image_bytes')->update([
            'image_bytes' => $bytes,
            'image_mime' => $mime,
            'updated_at' => now(),
        ]);
        if ($updated !== 1) {
            return redirect('/issues')->with('error', 'This task already has an image.');
        }
        return redirect('/issues')->with('success', 'Screenshot saved.');
    }

    public function show(Request $request): Response
    {
        $task = $this->authorizedTask($request);
        if ($task === null || !is_string($task->image_bytes)
            || !in_array($task->image_mime, ['image/png', 'image/jpeg'], true)) {
            return response('Not found.', 404)->header('Cache-Control', 'no-store');
        }
        return response($task->image_bytes, 200, [
            'Content-Type' => $task->image_mime,
            'Content-Disposition' => 'inline',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function authorizedTask(Request $request): ?WorkbenchTask
    {
        $taskId = $request->session()->get('issue_task_id');
        if (!is_int($taskId) && !ctype_digit((string) ($taskId ?? ''))) {
            return null;
        }
        $task = WorkbenchTask::whereKey((int) $taskId)->first();
        if ($task === null) {
            return null;
        }
        $access = $request->attributes->get('proofpatch_github_access');
        $userId = is_array($access) ? ($access['userId'] ?? null) : null;
        if (is_int($userId) && $userId > 0 && $task->github_user_id === $userId) {
            $repositories = $access['repositories'] ?? null;
            return is_array($repositories) && isset($repositories[strtolower((string) $task->repository)]) ? $task : null;
        }
        // Disconnected synthetic fixtures carry no user; screenshots stay local-only there.
        return $task->github_user_id === null && (bool) $task->is_synthetic ? $task : null;
    }
}
