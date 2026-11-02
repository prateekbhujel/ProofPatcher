<?php

declare(strict_types=1);

namespace ProofPatch\GitHub;

/** A safe, user-facing failure when the GitHub App bot is not configured. */
final class BotIdentityConfigurationException extends \RuntimeException {}
