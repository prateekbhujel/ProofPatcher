<?php

declare(strict_types=1);

namespace ProofPatch\Application\Review;

/**
 * Builds the review-preview redirect target, preserving the snapshot context
 * when one is loaded. Pure string building; the caller owns the redirect.
 */
final class ReviewPreviewRedirect
{
    public static function to(mixed $snapshot): string
    {
        $query = is_array($snapshot) && isset($snapshot['id']) ? '&snapshot='.$snapshot['id'] : '';
        return '/repositories?preview=1'.$query;
    }
}
