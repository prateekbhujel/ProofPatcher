<?php

declare(strict_types=1);

namespace ProofPatch\Application\Review;

/**
 * Entry guards for a manual review request: explicit confirmation plus a
 * well-formed preview digest and server-held approval token. These run
 * before any token consumption or provider call, so nothing billable can
 * start without them. Pure validation; the caller owns the paid flow.
 */
final class ManualReviewGuards
{
    public static function validate(mixed $confirm, mixed $digest, mixed $approvalToken): void
    {
        if (!$confirm) {
            throw new \InvalidArgumentException('You must review and explicitly approve the request before sending.');
        }
        if (!preg_match('/\A[a-f0-9]{64}\z/', (string) $digest)) {
            throw new \InvalidArgumentException('A valid preview digest is required.');
        }
        if (!preg_match('/\A[a-f0-9]{64}\z/', (string) $approvalToken)) {
            throw new \InvalidArgumentException('A valid server-held approval token is required.');
        }
    }
}
