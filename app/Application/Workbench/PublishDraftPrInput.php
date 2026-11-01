<?php

declare(strict_types=1);

namespace ProofPatch\Application\Workbench;

/**
 * Validated input for publishing a draft PR. Framework-free: the controller
 * copies plain values out of the session, and this object proves a verified
 * run, evidence, and authorization are present before the use case runs.
 */
final readonly class PublishDraftPrInput
{
    public function __construct(
        public array $context,
        public array $report,
        public array $suggestion,
        public string $candidateSource,
        public string $candidateSha,
        public string $authToken,
        public string $githubToken,
        public string $evidenceDir,
        public bool $isPrivateDedicated,
    ) {
    }

    /** @param array<string,mixed> $raw */
    public static function fromRaw(array $raw): self
    {
        $context = $raw['context'] ?? null;
        $report = $raw['report'] ?? null;
        $suggestion = $raw['suggestion'] ?? null;
        if (!is_array($context) || !is_array($report) || !is_array($suggestion) || !isset($suggestion['candidate']['source'])) {
            throw new \InvalidArgumentException('No verified candidate run available for publication.');
        }
        if (($raw['is_dedicated'] ?? false) !== true) {
            throw new \InvalidArgumentException('Pull request publication is strictly restricted to the dedicated test repository.');
        }
        $evidenceDir = $report['evidenceDirectory'] ?? null;
        if (!is_string($evidenceDir) || !is_dir($evidenceDir)) {
            throw new \InvalidArgumentException('Execution evidence directory is missing.');
        }
        $authToken = $raw['auth_token'] ?? null;
        if ($authToken === null || trim((string) $authToken) === '') {
            throw new \InvalidArgumentException('Publication refused: Server-held publication authorization token is required.');
        }
        $githubToken = $raw['github_token'] ?? null;
        if (!is_string($githubToken) || trim($githubToken) === '') {
            throw new \InvalidArgumentException('Authentication required: Connect your GitHub account before publishing.');
        }
        $candidateSource = (string) $suggestion['candidate']['source'];

        return new self(
            context: $context,
            report: $report,
            suggestion: $suggestion,
            candidateSource: $candidateSource,
            candidateSha: hash('sha256', $candidateSource),
            authToken: (string) $authToken,
            githubToken: $githubToken,
            evidenceDir: $evidenceDir,
            isPrivateDedicated: (bool) ($raw['is_private_dedicated'] ?? false),
        );
    }
}
