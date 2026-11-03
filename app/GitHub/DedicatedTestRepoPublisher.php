<?php

declare(strict_types=1);

namespace ProofPatch\GitHub;

use ProofPatch\Evidence\Receipt;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Dedicated publisher for verified ProofPatcher draft pull requests.
 *
 * Security & authorization constraints:
 * - Strictly allowlisted: only the configured dedicated test repository is allowed.
 * - Rejects any attempt to target unauthorized, private, or third-party repositories.
 * - Disconnected by default: Refuses external network connections until independent review approval.
 * - Mandatory server approval store: ServerHeldPublicationApproval is required; fail closed if missing.
 * - Unanchored local receipts do NOT authorize publication: requires a server-held, HMAC-signed
 *   single-use authorization token produced by the server-controlled live runner.
 * - Enforces exact issue #1, exact file path 'src/OrderTotal.php', and exact base commit
 *   the configured base commit; fails closed on any drift or fabrication.
 * - Enforces exact cryptographic digest binding for baseline, candidate, and test harness across
 *   both the on-disk evidence directory and the consumed server-held authorization record.
 * - Multi-layer secret scanning (entropy + known token formats + token containment checks).
 * - Strictly rejects mocked or simulated execution runs.
 * - Always opens pull requests as DRAFT (`draft: true`). Automatic merge is strictly forbidden.
 */
final readonly class DedicatedTestRepoPublisher
{
    public const AUTHORIZED_ISSUE_NUMBER = 1;
    public const AUTHORIZED_FILE_PATH = 'src/OrderTotal.php';

    public const KNOWN_BASE_SHA256 = '54bc37fb2b005badcf309c5a7091ba6a2e704fc54c8eff9e9c7afee7e4054859';
    public const KNOWN_TEST_SHA256 = 'b8079fb1e7731fc763f0b009a11c2786d01b52cce08f7431768a64e8a5ef2cbf';
    public const KNOWN_TEST_SHA256_DEDICATED = '263eb70cbf346221858b9c64ba97384105d3804089a653d63d016664e8c1320e';
    public const KNOWN_CANDIDATE_SHA256 = 'd102376bea8e1bdb745cf0f4026540574254e741a590be455a94dceb1a69f8fb';

    private const SENSITIVE_TOKEN_PATTERNS = [
        '/\bghp_[a-zA-Z0-9]{36}\b/',
        '/\bgho_[a-zA-Z0-9]{36}\b/',
        '/\bgithub_pat_[a-zA-Z0-9_]{50,}\b/',
        '/\bsk-[a-zA-Z0-9]{20,}\b/',
        '/\b(?:baseten|bearer)\s+[a-zA-Z0-9_\-\.]{16,}\b/i',
        '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
    ];

    public function __construct(
        private ServerHeldPublicationApproval $approvalStore,
        private ?HttpClientInterface $http = null,
        private bool $disconnected = true,
        private ?\ProofPatch\Agent\ServerHeldModelProposal $modelProposals = null
    ) {
    }

    private function getHttpClient(): HttpClientInterface
    {
        return $this->http ?? HttpClient::create();
    }

    public function isDisconnected(): bool
    {
        return $this->disconnected;
    }

    /** Publish an explicitly approved draft after finite fixture observations.
     * This separate path never creates or accepts a verified-publication token.
     */
    public function publishObservedDraft(array $context, array $suggestion, int $userId, string $approvalToken,
        string $githubToken, ObservedDraftApproval $observedApprovals): array
    {
        if ($this->disconnected || trim($githubToken) === '') throw new \RuntimeException('A connected GitHub session is required.');
        $client = $this->getHttpClient();
        $repo = DedicatedTestRepo::requireName();
        $user = $this->apiCall($client, 'GET', 'https://api.github.com/user', $githubToken);
        $repository = $this->apiCall($client, 'GET', 'https://api.github.com/repos/'.$repo, $githubToken);
        $issue = $this->apiCall($client, 'GET', 'https://api.github.com/repos/'.$repo.'/issues/1', $githubToken);
        [$owner] = explode('/', $repo, 2);
        if (($user['id'] ?? null) !== $userId || !is_string($user['login'] ?? null)
            || strcasecmp($user['login'], $owner) !== 0 || ($repository['private'] ?? null) !== true
            || !is_string($repository['full_name'] ?? null) || strcasecmp($repository['full_name'], $repo) !== 0
            || ($repository['default_branch'] ?? null) !== 'main'
            || ($issue['number'] ?? null) !== 1 || ($issue['state'] ?? null) !== 'open'
            || !is_string($issue['title'] ?? null) || !is_string($issue['body'] ?? null)
            || ($issue['title'] ?? null) !== ($context['title'] ?? null) || ($issue['body'] ?? null) !== ($context['body'] ?? null)) {
            throw new \RuntimeException('The signed-in owner and open private test issue could not be verified.');
        }
        $candidate = $suggestion['candidate']['source'] ?? null;
        if (!is_string($candidate)) throw new \InvalidArgumentException('Candidate is missing.');
        $candidateSha = hash('sha256', $candidate);
        // Confirm the server-held private proposal is still bound to this GitHub identity and grant.
        $this->verifyPrivateProvenance(array_merge($context, [
            'privateProvenanceId' => $suggestion['privateProvenanceId'] ?? null,
            'model' => $suggestion['model'] ?? null, 'requestDigest' => $suggestion['requestDigest'] ?? null,
        ]), $candidateSha, DedicatedTestRepoFetcher::EXPECTED_SOURCE_SHA256, $githubToken);
        $this->performSecretAudit($candidate, 'candidateSource', $githubToken);
        // Check for an identical existing draft before any new GitHub write.
        // An incomplete listing fails closed rather than creating a duplicate.
        $existing = $this->findObservedDraft($githubToken, $candidateSha);
        $observedApprovals->consume($approvalToken, $userId, $context, $suggestion);
        if ($existing !== null) return $existing;
        $runId = substr(hash('sha256', $approvalToken), 0, 24);
        $branch = self::branchName((string) ($context['title'] ?? 'order total'), 1, $runId);
        $body = "Fixes #1.\n\nMuse proposed this change. It matched the included local order-total fixture in isolated test runs; the baseline and negative control did not. These are finite observations, not proof of correctness. Please review the patch and run the repository's own tests before merging.";
        $this->performSecretAudit($body, 'prBody', $githubToken);
        try {
            $result = $this->executePublish($githubToken, $branch, 'fix: correct order total', $body,
                DedicatedTestRepoFetcher::SOURCE_PATH, $candidate, ['id' => $runId]);
            $result['verificationRunId'] = '';
            $result['receiptSha256'] = '';
            $result['observationScope'] = 'finite_local_fixture';
            return $result;
        } catch (\Throwable $error) {
            throw new \ProofPatch\Exceptions\AmbiguousPublicationException(
                'The draft publication may have started. Check the branch and reconcile before trying again.', $repo, $branch, $runId, $error
            );
        }
    }

    private function findObservedDraft(string $token, string $candidateSha): ?array
    {
        $client = $this->getHttpClient();
        $repo = DedicatedTestRepo::requireName();
        $root = 'https://api.github.com/repos/'.$repo;
        $found = null;
        for ($page = 1; $page <= 5; $page++) {
            $pulls = $this->apiCall($client, 'GET', $root.'/pulls?state=open&per_page=100&page='.$page, $token);
            if (!array_is_list($pulls) || count($pulls) > 100) throw new \RuntimeException('Cannot confirm existing drafts.');
            foreach ($pulls as $pull) {
                if (!is_array($pull)) throw new \RuntimeException('Cannot confirm existing drafts.');
                if (($pull['draft'] ?? null) !== true || ($pull['base']['ref'] ?? null) !== 'main'
                    || ($pull['base']['sha'] ?? null) !== DedicatedTestRepo::requireBaseCommit()
                    || ($pull['head']['repo']['full_name'] ?? null) !== $repo) continue;
                $number = $pull['number'] ?? null;
                $head = $pull['head']['sha'] ?? null;
                $branch = $pull['head']['ref'] ?? null;
                $url = $pull['html_url'] ?? null;
                if (!is_int($number) || $number < 1 || !is_string($head) || !preg_match('/\A[a-f0-9]{40}\z/', $head)
                    || !is_string($branch) || $branch === '' || $url !== 'https://github.com/'.$repo.'/pull/'.$number) {
                    throw new \RuntimeException('Cannot confirm existing drafts.');
                }
                $files = $this->apiCall($client, 'GET', $root.'/pulls/'.$number.'/files?per_page=100&page=1', $token);
                if (!array_is_list($files) || count($files) >= 100) throw new \RuntimeException('Cannot confirm existing draft files.');
                if (count($files) !== 1 || ($files[0]['filename'] ?? null) !== DedicatedTestRepoFetcher::SOURCE_PATH
                    || ($files[0]['status'] ?? null) !== 'modified') continue;
                $file = $this->apiCall($client, 'GET', $root.'/contents/'.DedicatedTestRepoFetcher::SOURCE_PATH.'?ref='.$head, $token);
                $content = is_string($file['content'] ?? null) ? base64_decode(preg_replace('/\s+/', '', $file['content']), true) : false;
                if (($file['encoding'] ?? null) !== 'base64' || !is_string($content) || strlen($content) > 65536) {
                    throw new \RuntimeException('Cannot inspect existing draft content.');
                }
                if (hash_equals($candidateSha, hash('sha256', $content))) {
                    $found = ['status' => 'existing', 'prNumber' => $number, 'prUrl' => $url, 'branch' => $branch,
                        'commitSha' => $head, 'draft' => true, 'repository' => $repo, 'observationScope' => 'finite_local_fixture'];
                }
            }
            if (count($pulls) < 100) return $found;
        }
        throw new \RuntimeException('Too many open pull requests to check for duplicate drafts.');
    }

    /**
     * Publishes a verified candidate proposal as a draft PR in the dedicated test repository.
     *
     * Validates identity, issue, path, base commit, server-held authorization token,
     * independently verifies the immutable execution evidence directory, and compares
     * every single field of the consumed server record against the request/report before initiating remote calls.
     *
     * @param array{
     *     repository: string,
     *     issueNumber: int,
     *     path: string,
     * } $context
     * @param string $evidenceDirectory Path to on-disk run evidence directory
     * @param string $candidateSource The exact candidate file content
     * @param ?string $authorizationToken Server-held single-use authorization token
     * @param ?string $githubToken GitHub OAuth/Personal Access Token
     * @return array{
     *     status: string,
     *     prNumber: int,
     *     prUrl: string,
     *     branch: string,
     *     commitSha: string,
     *     draft: bool,
     *     repository: string,
     *     publishedAt: string,
     *     receiptSha256: string
     * }
     */
    public function publishDraftPr(
        array $context,
        string $evidenceDirectory,
        string $candidateSource,
        ?string $authorizationToken = null,
        ?string $githubToken = null
    ): array {
        DedicatedTestRepo::requireBaseCommit();
        // Boundary 1: Strict repository allowlist
        $repo = trim((string) ($context['repository'] ?? ''));
        if ($repo !== DedicatedTestRepo::requireName()) {
            throw new \InvalidArgumentException(
                "Repository '{$repo}' is not authorized. ProofPatcher GitHub publishing is strictly restricted to " . DedicatedTestRepo::requireName() . '.'
            );
        }

        // Boundary 2: Strict issue number binding
        $issueNum = (int) ($context['issueNumber'] ?? 0);
        if ($issueNum !== self::AUTHORIZED_ISSUE_NUMBER) {
            throw new \InvalidArgumentException(
                "Issue #{$issueNum} is not authorized for publication. Only Issue #" . self::AUTHORIZED_ISSUE_NUMBER . ' is authorized.'
            );
        }

        // Boundary 3: Strict file path binding
        $filePath = trim((string) ($context['path'] ?? ''));
        if ($filePath !== self::AUTHORIZED_FILE_PATH) {
            throw new \InvalidArgumentException(
                "File path '{$filePath}' is not authorized. Only '" . self::AUTHORIZED_FILE_PATH . "' is authorized."
            );
        }

        // Boundary 4: Verify immutable execution evidence directory and receipt directly from disk
        $verificationReport = $this->verifyEvidenceDirectory($evidenceDirectory);

        // Boundary 5: Revalidate candidate bytes against verified report digest
        $candidateSha = hash('sha256', $candidateSource);
        if (!hash_equals((string) ($verificationReport['candidateSha256'] ?? ''), $candidateSha)) {
            throw new \InvalidArgumentException(
                'Candidate source hash does not match allowlisted candidate digest. Untrusted candidate cannot be published.'
            );
        }
        $isTrustedFixture = hash_equals(\ProofPatch\Verifier\OwnedCaseVerifier::KNOWN_TRUSTED_CANDIDATE_SHA256, $candidateSha);
        $isIsolatedVerified = ($verificationReport['oracleVerified'] ?? false) === true
            && ($verificationReport['isolated'] ?? false) === true
            && ($verificationReport['genuineProviderEvidence'] ?? false) === true;

        if (!$isTrustedFixture && !$isIsolatedVerified) {
            throw new \InvalidArgumentException('Publication refused: the test oracle is not isolated from untrusted candidates.');
        }

        // Boundary 5b: Cryptographically verify model provenance if claimed.
        // Private dedicated claims require owner-scoped provenance only; a
        // public record for the same bytes is not a substitute binding.
        $proposalOrigin = $context['proposalOrigin'] ?? null;
        if ($proposalOrigin === 'muse' || !empty($context['model'])) {
            if (($context['isPrivateDedicated'] ?? false) === true) {
                $privateRecord = $this->verifyPrivateProvenance(
                    $context,
                    $candidateSha,
                    (string) ($verificationReport['baseSha256'] ?? ''),
                    $githubToken ?: $this->resolveToken()
                );
                $context['usage'] = $privateRecord['usage'];
            } else {
                $proposalStore = $this->modelProposals ?? new \ProofPatch\Agent\ServerHeldModelProposal();
                $serverProposal = $proposalStore->lookup($candidateSha);
                if ($serverProposal === null) {
                    throw new \InvalidArgumentException(
                        'Publication refused: Candidate claims Muse model provenance but has no verified server-held model proposal.'
                    );
                }
                if ($serverProposal['model'] !== ($context['model'] ?? 'meta-models/muse-spark-open-1026')) {
                    throw new \InvalidArgumentException('Publication refused: Model name mismatch against server-held proposal.');
                }
                if (!hash_equals($serverProposal['sourceSha256'], (string) ($verificationReport['baseSha256'] ?? ''))) {
                    throw new \InvalidArgumentException('Publication refused: Target file source hash mismatch against model proposal source.');
                }
                // Bind usage to verified server-held usage
                $context['usage'] = $serverProposal['usage'];
            }
        }

        // Boundary 6: Revalidate base fixture and test harness digests from disk
        if (($verificationReport['baseSha256'] ?? '') !== self::KNOWN_BASE_SHA256) {
            throw new \InvalidArgumentException('Base fixture hash does not match verified baseline digest.');
        }

        if (!in_array($verificationReport['testSha256'] ?? '', [self::KNOWN_TEST_SHA256, self::KNOWN_TEST_SHA256_DEDICATED], true)) {
            throw new \InvalidArgumentException('Test harness hash does not match verified test digest.');
        }

        // Boundary 7: Server-held authenticated state check
        // Unanchored local receipts do NOT prove origin or authorization.
        if ($authorizationToken === null || trim($authorizationToken) === '') {
            throw new \InvalidArgumentException(
                'Publication refused: Server-held publication authorization token is required. Locally generated unanchored receipts cannot authorize pull request publication.'
            );
        }

        // Boundary 8: Safety killswitch check - Keep publisher disconnected until independent review
        // Must fail closed BEFORE consuming any server-held authorization token or mutating state.
        if ($this->disconnected) {
            throw new \RuntimeException(
                'Publisher is currently in disconnected safety mode pending independent review approval. External GitHub connections are blocked.'
            );
        }

        // Boundary 9: Require authorized GitHub session token before consuming approval
        $resolvedGithubToken = $githubToken ?: $this->resolveToken();
        if ($resolvedGithubToken === null || trim($resolvedGithubToken) === '') {
            throw new \RuntimeException(
                'GitHub authorization token is required to publish draft PR. An authorized app session is required.'
            );
        }

        // Atomically consume token and verify signature
        $consumedRecord = $this->approvalStore->verifyAndConsume(
            $authorizationToken,
            (string) ($verificationReport['id'] ?? ''),
            $evidenceDirectory,
            (string) ($verificationReport['receipt']['sha256'] ?? ''),
            $candidateSha,
            $context['privateProvenanceId'] ?? null
        );

        // Boundary 10: Verify all binding fields of the consumed record against the request/report
        if ($consumedRecord['repository'] !== $repo) {
            throw new \InvalidArgumentException(
                "Authorization record repository mismatch: expected '{$consumedRecord['repository']}', got '{$repo}'."
            );
        }

        if ((int) $consumedRecord['issueNumber'] !== $issueNum) {
            throw new \InvalidArgumentException(
                "Authorization record issue mismatch: expected #{$consumedRecord['issueNumber']}, got #{$issueNum}."
            );
        }

        if ($consumedRecord['filePath'] !== $filePath) {
            throw new \InvalidArgumentException(
                "Authorization record file path mismatch: expected '{$consumedRecord['filePath']}', got '{$filePath}'."
            );
        }

        if (!hash_equals($consumedRecord['baseSha256'], (string) ($verificationReport['baseSha256'] ?? ''))) {
            throw new \InvalidArgumentException('Authorization record base SHA-256 does not match verified report.');
        }

        if (!hash_equals($consumedRecord['testSha256'], (string) ($verificationReport['testSha256'] ?? ''))) {
            throw new \InvalidArgumentException('Authorization record test SHA-256 does not match verified report.');
        }

        if (!hash_equals($consumedRecord['candidateSha256'], $candidateSha)) {
            throw new \InvalidArgumentException('Authorization record candidate SHA-256 does not match candidate source.');
        }

        if ($consumedRecord['baseCommit'] !== DedicatedTestRepo::requireBaseCommit()) {
            throw new \InvalidArgumentException('Authorization record base commit is not authorized.');
        }

        if (!empty($consumedRecord['isSimulated'])) {
            throw new \InvalidArgumentException('Publication refused: Simulated runs cannot authorize draft PR publication.');
        }

        // Boundary 10: Multi-layer secret scanning (before making any network calls)
        $rawRunId = (string) ($verificationReport['id'] ?? bin2hex(random_bytes(8)));
        $sanitizedRunId = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', $rawRunId));
        $sanitizedRunId = trim(preg_replace('/-+/', '-', $sanitizedRunId), '-');
        if (strlen($sanitizedRunId) < 8) {
            $sanitizedRunId = bin2hex(random_bytes(8));
        }
        $branchName = self::branchName((string) ($context['title'] ?? ''), self::AUTHORIZED_ISSUE_NUMBER, $sanitizedRunId);
        $commitMessage = $this->buildCommitMessage($verificationReport, $sanitizedRunId);
        $prBody = $this->buildPrBody($context, $verificationReport, $sanitizedRunId);

        $this->performSecretAudit($candidateSource, 'candidateSource', $resolvedGithubToken);
        $this->performSecretAudit($branchName, 'branchName', $resolvedGithubToken);
        $this->performSecretAudit($commitMessage, 'commitMessage', $resolvedGithubToken);
        $this->performSecretAudit($prBody, 'prBody', $resolvedGithubToken);

        // Execute remote publish
        try {
            return $this->executePublish(
                $resolvedGithubToken,
                $branchName,
                $commitMessage,
                $prBody,
                self::AUTHORIZED_FILE_PATH,
                $candidateSource,
                $verificationReport
            );
        } catch (\ProofPatch\Exceptions\AmbiguousPublicationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \ProofPatch\Exceptions\AmbiguousPublicationException(
                "GitHub error during draft PR publication: " . $e->getMessage(),
                DedicatedTestRepo::requireName(),
                $branchName,
                $rawRunId,
                $e
            );
        }
    }

    /**
     * Verifies the cryptographic receipt and result.json directly from disk.
     * Does NOT trust any caller-supplied in-memory report array.
     */
    public function verifyEvidenceDirectory(string $evidenceDirectory): array
    {
        if (!is_dir($evidenceDirectory)) {
            throw new \InvalidArgumentException("Evidence directory '{$evidenceDirectory}' does not exist on disk.");
        }

        $receiptFile = $evidenceDirectory . '/receipt.json';
        $resultFile = $evidenceDirectory . '/result.json';

        if (!is_file($receiptFile) || !is_file($resultFile)) {
            throw new \InvalidArgumentException('Evidence directory is missing receipt.json or result.json.');
        }

        // Verify cryptographic receipt manifest against actual on-disk files
        $receiptHelper = new Receipt();
        $verification = $receiptHelper->verify($evidenceDirectory);

        if (($verification['integrity'] ?? '') !== 'VALID') {
            throw new \InvalidArgumentException(
                "Evidence directory failed cryptographic receipt verification: integrity is '{$verification['integrity']}'."
            );
        }

        $resultContent = (string) file_get_contents($resultFile);
        $report = json_decode($resultContent, true);
        if (!is_array($report)) {
            throw new \InvalidArgumentException('result.json in evidence directory is corrupted or not valid JSON.');
        }

        // Ensure decision is VERIFIED
        if (($report['decision'] ?? '') !== 'VERIFIED') {
            throw new \InvalidArgumentException(
                "Cannot publish unverified proposal: On-disk report decision is '" . ($report['decision'] ?? 'NONE') . "', expected 'VERIFIED'."
            );
        }

        // Ensure all 5 verification gates passed
        $gates = $report['gates'] ?? [];
        if (
            empty($gates['baselineReproduced']) ||
            empty($gates['negativeControlCaught']) ||
            empty($gates['candidatePassed']) ||
            empty($gates['candidateStaticSafetyPassed']) ||
            empty($gates['allowlistIntegrityIntact'])
        ) {
            throw new \InvalidArgumentException('Cannot publish proposal: All 5 verification gates must pass in on-disk result.');
        }

        $report['receipt'] = [
            'sha256' => $verification['sha256'],
            'integrity' => $verification['integrity'],
        ];

        return $report;
    }

    private function executePublish(
        string $token,
        string $branchName,
        string $commitMessage,
        string $prBody,
        string $filePath,
        string $fileContent,
        array $verificationReport
    ): array {
        $client = $this->getHttpClient();
        $repoApi = 'https://api.github.com/repos/' . DedicatedTestRepo::requireName();

        // 1. Get base branch commit SHA (main) and enforce AUTHORIZED_BASE_COMMIT
        $refRes = $this->apiCall($client, 'GET', "{$repoApi}/git/ref/heads/main", $token);
        $remoteBaseSha = (string) ($refRes['object']['sha'] ?? '');

        if (!preg_match('/\A[a-f0-9]{40}\z/', $remoteBaseSha)) {
            throw new \RuntimeException('Failed to resolve main branch commit SHA from GitHub API.');
        }

        // Fail closed if main branch has drifted from authorized base commit
        if ($remoteBaseSha !== DedicatedTestRepo::requireBaseCommit()) {
            throw new \RuntimeException(
                "Refusing to publish against stale or drifted base commit. Remote main is at '{$remoteBaseSha}', but authorized base commit is '" . DedicatedTestRepo::requireBaseCommit() . "'."
            );
        }

        // 2. Get tree SHA of base commit
        $baseCommitRes = $this->apiCall($client, 'GET', "{$repoApi}/git/commits/{$remoteBaseSha}", $token);
        $baseTreeSha = (string) ($baseCommitRes['tree']['sha'] ?? '');
        if (!preg_match('/\A[a-f0-9]{40}\z/', $baseTreeSha)) {
            throw new \RuntimeException('Failed to resolve base commit tree SHA.');
        }

        // 3. Create blob for candidate file
        $blobRes = $this->apiCall($client, 'POST', "{$repoApi}/git/blobs", $token, [
            'content' => $fileContent,
            'encoding' => 'utf-8',
        ]);
        $blobSha = (string) ($blobRes['sha'] ?? '');
        if (!preg_match('/\A[a-f0-9]{40}\z/', $blobSha)) {
            throw new \RuntimeException('Failed to create candidate file blob.');
        }

        // 4. Create new tree referencing the blob
        $treeRes = $this->apiCall($client, 'POST', "{$repoApi}/git/trees", $token, [
            'base_tree' => $baseTreeSha,
            'tree' => [
                [
                    'path' => $filePath,
                    'mode' => '100644',
                    'type' => 'blob',
                    'sha' => $blobSha,
                ],
            ],
        ]);
        $newTreeSha = (string) ($treeRes['sha'] ?? '');
        if (!preg_match('/\A[a-f0-9]{40}\z/', $newTreeSha)) {
            throw new \RuntimeException('Failed to create git tree.');
        }

        // 5. Create commit
        $commitRes = $this->apiCall($client, 'POST', "{$repoApi}/git/commits", $token, [
            'message' => $commitMessage,
            'tree' => $newTreeSha,
            'parents' => [$remoteBaseSha],
        ]);
        $newCommitSha = (string) ($commitRes['sha'] ?? '');
        if (!preg_match('/\A[a-f0-9]{40}\z/', $newCommitSha)) {
            throw new \RuntimeException('Failed to create git commit.');
        }

        // 6. Create ref (branch)
        $this->apiCall($client, 'POST', "{$repoApi}/git/refs", $token, [
            'ref' => "refs/heads/{$branchName}",
            'sha' => $newCommitSha,
        ]);

        // 7. Create Draft Pull Request
        $prTitle = 'fix(order-total): correct zero-quantity pricing';
        $prRes = $this->apiCall($client, 'POST', "{$repoApi}/pulls", $token, [
            'title' => $prTitle,
            'head' => $branchName,
            'base' => 'main',
            'body' => $prBody,
            'draft' => true,
        ]);

        $prNumber = (int) ($prRes['number'] ?? 0);
        $prUrl = (string) ($prRes['html_url'] ?? '');
        $expectedUrl = "https://github.com/" . DedicatedTestRepo::requireName() . "/pull/{$prNumber}";
        $isDraft = (bool) ($prRes['draft'] ?? false);

        if ($prNumber <= 0 || !hash_equals($expectedUrl, $prUrl) || !$isDraft) {
            throw new \ProofPatch\Exceptions\AmbiguousPublicationException(
                "Malformed pull request creation response from GitHub: positive number, draft status, and authorized repo URL required. Received number: {$prNumber}, url: '{$prUrl}'.",
                DedicatedTestRepo::requireName(),
                $branchName,
                (string) ($verificationReport['id'] ?? '')
            );
        }

        return [
            'status' => 'published',
            'prNumber' => $prNumber,
            'prUrl' => $prUrl,
            'branch' => $branchName,
            'commitSha' => $newCommitSha,
            'draft' => true,
            'repository' => DedicatedTestRepo::requireName(),
            'publishedAt' => gmdate(DATE_ATOM),
            'receiptSha256' => (string) ($verificationReport['receipt']['sha256'] ?? ''),
            'verificationRunId' => (string) ($verificationReport['id'] ?? ''),
        ];
    }

    public function reconcileExistingPr(string $githubToken, string $branchName): ?array
    {
        DedicatedTestRepo::requireBaseCommit();
        $client = $this->getHttpClient();
        $repoApi = 'https://api.github.com/repos/' . DedicatedTestRepo::requireName();

        $owner = explode('/', DedicatedTestRepo::requireName())[0];
        $headQuery = "{$owner}:{$branchName}";
        $res = $this->apiCall($client, 'GET', "{$repoApi}/pulls?head=" . rawurlencode($headQuery) . '&state=all', $githubToken);
        if (!array_is_list($res)) {
            throw new \RuntimeException('GitHub returned an invalid pull request list.');
        }

        if (count($res) > 0) {
            $prData = $res[0];
            $prNumber = (int) ($prData['number'] ?? 0);
            $prUrl = (string) ($prData['html_url'] ?? '');
            $expectedUrl = 'https://github.com/' . DedicatedTestRepo::requireName() . "/pull/{$prNumber}";

            if ($prNumber > 0 && hash_equals($expectedUrl, $prUrl) && ($prData['draft'] ?? null) === true
                && ($prData['head']['ref'] ?? null) === $branchName
                && ($prData['head']['repo']['full_name'] ?? null) === DedicatedTestRepo::requireName()) {
                return [
                    'status' => 'published',
                    'prNumber' => $prNumber,
                    'prUrl' => $prUrl,
                    'branch' => $branchName,
                    'commitSha' => (string) ($prData['head']['sha'] ?? ''),
                    'draft' => true,
                    'repository' => DedicatedTestRepo::requireName(),
                    'publishedAt' => gmdate(DATE_ATOM),
                ];
            }
            throw new \RuntimeException('GitHub returned a pull request that does not match the expected draft branch.');
        }

        return null;
    }

    /**
     * Readable, unique branch name: proofpatch/<issue>-<title-slug>-<6 hex>.
     * The suffix is derived from the verification run so retries of one run reuse the branch.
     */
    public static function branchName(string $title, int $issueNumber, string $runId): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $title));
        $slug = trim($slug, '-');
        if (strlen($slug) > 40) {
            $cut = substr($slug, 0, 41);
            $slug = rtrim(str_contains($cut, '-') ? substr($cut, 0, (int) strrpos($cut, '-')) : substr($cut, 0, 40), '-');
        }
        $slug = $slug === '' ? 'fix' : $slug;

        return 'proofpatch/'.$issueNumber.'-'.$slug.'-'.substr(hash('sha256', $runId), 0, 6);
    }

    private function buildCommitMessage(array $report, string $shortId): string
    {
        $receiptSha = (string) ($report['receipt']['sha256'] ?? 'none');
        $baseCommit = substr(DedicatedTestRepo::requireBaseCommit(), 0, 7);

        return <<<COMMIT
fix(order-total): correct zero-quantity item pricing (ProofPatcher #1)

Bounded fixture checks completed.
Run ID: {$report['id']}
Receipt SHA-256: {$receiptSha}
Integrity: VALID (5/5 Verification Gates Passed)
Baseline Reproduced: Yes (Exit 1)
Negative Control Caught: Yes (Exit 1)
Candidate Sandbox Check: Passed (Exit 0 - Replicated bounded cases)
Base Commit: {$baseCommit}

Co-authored-by: proofpatcher[bot] <349779349+proofpatcher[bot]@users.noreply.github.com>
COMMIT;
    }

    private function buildPrBody(array $context, array $report, string $shortId): string
    {
        $receiptSha = (string) ($report['receipt']['sha256'] ?? 'none');
        $runId = (string) ($report['id'] ?? 'unknown');
        $baseSha = (string) ($report['baseSha256'] ?? self::KNOWN_BASE_SHA256);
        $candidateSha = (string) ($report['candidateSha256'] ?? self::KNOWN_CANDIDATE_SHA256);
        $testSha = (string) ($report['testSha256'] ?? self::KNOWN_TEST_SHA256);
        $baseCommit = DedicatedTestRepo::requireBaseCommit();
        $targetRepository = DedicatedTestRepo::requireName();
        $tokensInfo = isset($context['usage']) && is_array($context['usage'])
            ? " (Tokens: {$context['usage']['prompt_tokens']} prompt, {$context['usage']['completion_tokens']} completion)"
            : '';
        $modelName = $context['model'] ?? 'meta-models/muse-spark-open-1026';
        $provider = in_array($modelName, \ProofPatch\Agent\ProviderConfigStore::PROVIDERS['baseten']['models'], true)
            ? 'Baseten' : (in_array($modelName, \ProofPatch\Agent\ProviderConfigStore::PROVIDERS['meta']['models'], true)
                ? 'Meta Model API' : 'the configured provider');
        $modelContext = ($context['isPrivateDedicated'] ?? false) === true
            ? 'the private test issue and selected source file' : 'the public fixture context';
        $origin = match ($context['proposalOrigin'] ?? null) {
            'offline_reference' => 'This is the known offline reference repair. No model was called for this proposal.',
            'muse' => "Candidate proposed by {$modelName} via {$provider} using {$modelContext}{$tokensInfo}. Applied to the dedicated test repository with matching source SHA-256.",
            default => 'The candidate matches the pinned reference repair.',
        };

        return <<<MARKDOWN
Fixes #1: zero-quantity items no longer add one unit to the order total.

ProofPatcher ran bounded checks against the OrderTotal fixture; the full repository test suite was not run. This is a draft for maintainer review.

<details>
<summary>Verification details</summary>

{$origin}

### Bounded fixture checks
| Gate | Expected | Observed | Status |
| :--- | :--- | :--- | :--- |
| **1. Baseline Failure Reproduction** | Exit 1 (Bug reproduced) | Exit {$report['runs']['baseline']['exitCode']} | PASSED |
| **2. Negative Control Guard** | Exit 1 (Trivial return 0 rejected) | Exit {$report['runs']['negative']['exitCode']} | PASSED |
| **3. Candidate Replicated Case Check** | Exit 0 (Replicated bounded cases passed) | Exit {$report['runs']['candidate']['exitCode']} | PASSED |
| **4. AST Static Safety** | 0 disallowed tokens | 0 Violations | PASSED |
| **5. Fixture Allowlist** | Known SHA-256 digests | Matched & Intact | PASSED |

### Cryptographic Manifest
- **Verification Run ID**: `{$runId}`
- **Receipt SHA-256**: `{$receiptSha}`
- **Baseline Source SHA-256**: `{$baseSha}`
- **Candidate Source SHA-256**: `{$candidateSha}`
- **Test Harness SHA-256**: `{$testSha}`
- **Target Repository**: `{$targetRepository}`
- **Bound Base Commit**: `{$baseCommit}`
- **Target Branch**: `main`

</details>
MARKDOWN;
    }

    /**
     * Verifies owner-scoped private provenance against live GitHub access.
     *
     * Rechecks the token identity and repository access with read-only API
     * calls, then requires an encrypted record for the exact binding. A
     * forged flag, revoked access, or changed bytes all fail closed.
     *
     * @return array{usage: array{prompt_tokens: int, completion_tokens: int}, model: string}
     */
    private function verifyPrivateProvenance(array $context, string $candidateSha, string $baseSha, ?string $githubToken): array
    {
        if ($githubToken === null || trim($githubToken) === '') {
            throw new \InvalidArgumentException('Publication refused: private model provenance requires the authorized GitHub session.');
        }
        $client = $this->getHttpClient();
        $user = $this->apiCall($client, 'GET', 'https://api.github.com/user', $githubToken);
        $userId = $user['id'] ?? null;
        if (!is_int($userId) || $userId < 1) {
            throw new \InvalidArgumentException('Publication refused: GitHub identity could not be verified.');
        }
        $repo = DedicatedTestRepo::requireName();
        $repoData = $this->apiCall($client, 'GET', 'https://api.github.com/repos/' . $repo, $githubToken);
        if (!is_string($repoData['full_name'] ?? null) || strcasecmp($repoData['full_name'], $repo) !== 0) {
            throw new \InvalidArgumentException('Publication refused: repository access could not be verified.');
        }
        $runId = $context['privateProvenanceId'] ?? null;
        if (!is_string($runId) || !preg_match('/\A[a-f0-9]{32}\z/', $runId)) {
            throw new \InvalidArgumentException('Publication refused: a recorded private proposal run is required.');
        }
        $record = \ProofPatch\Agent\PrivateProposalStore::forRuntime()->lookup(
            ['githubUserId' => $userId, 'repository' => $repo,
                'issueNumber' => self::AUTHORIZED_ISSUE_NUMBER, 'baseCommit' => DedicatedTestRepo::requireBaseCommit(),
                'path' => self::AUTHORIZED_FILE_PATH, 'sourceSha256' => $baseSha, 'candidateSha256' => $candidateSha],
            ['userId' => $userId, 'repositories' => [strtolower($repo) => true]],
            $runId
        );
        if ($record === null) {
            throw new \InvalidArgumentException('Publication refused: no private model provenance matches this change and current access.');
        }
        if (($record['model'] ?? null) !== ($context['model'] ?? 'meta-models/muse-spark-open-1026')) {
            throw new \InvalidArgumentException('Publication refused: Model name mismatch against private proposal.');
        }
        $requestDigest = $context['requestDigest'] ?? null;
        if (!is_string($requestDigest) || !hash_equals((string) ($record['requestDigest'] ?? ''), $requestDigest)) {
            throw new \InvalidArgumentException('Publication refused: private request digest does not match.');
        }
        return $record;
    }

    /**
     * Multi-layer secret audit: checks token containment, format patterns, and entropy.
     */
    private function performSecretAudit(string $content, string $fieldName, ?string $activeToken = null): void
    {
        if ($activeToken !== null && strlen($activeToken) >= 10 && str_contains($content, $activeToken)) {
            throw new \RuntimeException("Outbound {$fieldName} contains active authorization token. Refusing to publish.");
        }

        $envTokens = array_merge(
            [getenv('GITHUB_TOKEN'), getenv('BASETEN_API_KEY'), getenv('META_MODEL_API_KEY'), getenv('MODEL_API_KEY')],
            \ProofPatch\Agent\ProviderConfigStore::allConfiguredSecrets()
        );
        foreach ($envTokens as $envTok) {
            if (is_string($envTok) && strlen($envTok) >= 16 && str_contains($content, $envTok)) {
                throw new \RuntimeException("Outbound {$fieldName} contains environment token. Refusing to publish.");
            }
        }

        foreach (self::SENSITIVE_TOKEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $content)) {
                throw new \RuntimeException("Potential credential or sensitive token pattern detected in {$fieldName}. Refusing to publish.");
            }
        }

        preg_match_all('/\b[A-Za-z0-9_\-\.]{32,}\b/', $content, $matches);
        foreach ($matches[0] as $tokenCandidate) {
            if (preg_match('/\A[a-f0-9]{64}\z/', $tokenCandidate) || preg_match('/\A[a-f0-9]{40}\z/', $tokenCandidate)) {
                continue;
            }

            if ($this->calculateShannonEntropy($tokenCandidate) > 4.5) {
                throw new \RuntimeException("High-entropy token candidate detected in {$fieldName}. Refusing to publish.");
            }
        }
    }

    private function calculateShannonEntropy(string $str): float
    {
        $len = strlen($str);
        if ($len === 0) {
            return 0.0;
        }

        $freq = count_chars($str, 1);
        $entropy = 0.0;
        foreach ($freq as $count) {
            $p = $count / $len;
            $entropy -= $p * log($p, 2);
        }

        return $entropy;
    }

    private function apiCall(
        HttpClientInterface $client,
        string $method,
        string $url,
        string $token,
        ?array $json = null
    ): array {
        $options = [
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'Authorization' => 'Bearer ' . $token,
                'User-Agent' => 'ProofPatcher',
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
            'timeout' => 15,
        ];

        if ($json !== null) {
            $options['json'] = $json;
        }

        $res = $client->request($method, $url, $options);
        $status = $res->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $errContent = $res->getContent(false);
            throw new \RuntimeException("GitHub API {$method} {$url} failed with HTTP {$status}: {$errContent}");
        }

        $content = $res->getContent(false);
        if ((str_contains($url, '/pulls?') || preg_match('~/pulls/[0-9]+/files\?~', $url))
            && !str_starts_with(ltrim($content), '[')) {
            throw new \RuntimeException('GitHub returned an invalid pull request list.');
        }
        $data = json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException("GitHub API returned non-JSON response for {$url}.");
        }

        return $data;
    }

    private function resolveToken(): ?string
    {
        // Require the authorized app session. Implicit host CLI or environment token fallbacks are forbidden.
        if (function_exists('request')) {
            try {
                $req = request();
                if ($req && $req->hasSession()) {
                    $auth = $req->session()->get('github_auth');
                    if (is_array($auth) && isset($auth['token']) && is_string($auth['token']) && trim($auth['token']) !== '') {
                        return trim($auth['token']);
                    }
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
