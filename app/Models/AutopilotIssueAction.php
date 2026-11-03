<?php

declare(strict_types=1);

namespace ProofPatch\Models;

use Illuminate\Database\Eloquent\Model;

final class AutopilotIssueAction extends Model
{
    protected $table = 'autopilot_issue_actions';

    protected $fillable = ['repository', 'issue_number', 'issue_revision', 'action', 'detail', 'published'];

    protected $casts = ['published' => 'boolean'];
}
