# ProofPatcher

> A project for the **Meta Global AI Developer Hackathon 2026** (Indie Developer Track).

ProofPatcher is a local prototype for inspecting GitHub pull requests and testing sample UI repairs. It records PR metadata and existing checks and can show a read-only preview of changes from a public PR at the captured commits. Its visual verifier currently runs on bundled checkout fixtures, not on the selected PR. A worktree is not a security sandbox for untrusted code.

---

## Quickstart

### 1. Requirements
* PHP 8.4+ with `ext-gd` and `ext-json`
* Composer
* Node.js 20+ and npm
* Chrome / Chromium (for headless browser verification)

### 2. Setup
For a new installation, install dependencies, create a private application key, build frontend assets, and prepare the local SQLite database. Keep the generated `.env` private and do not regenerate the key for an existing installation:

```bash
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
php artisan migrate
```

### 3. Run the Web Dashboard
Start the local server using standard Laravel Artisan:

```bash
php artisan serve
```

Open **http://127.0.0.1:8000** in your browser.

*(Alternatively, run `php artisan serve --port=8431`).*

To enable GitHub sign-in, the operator sets `PROOFPATCH_GITHUB_CLIENT_ID` in `.env` to a GitHub App client ID with device flow enabled. Install that app on the repositories it should access. Users then select **Continue with GitHub** and enter the short code on GitHub; they do not need to enter the client ID themselves.

For an existing installation, back up the SQLite database before running `php artisan migrate`. Rolling back the task-card migrations deletes the cards or their screenshots.

### Issue suggestions

The Issues page also lets a connected user save task cards and one PNG or JPEG screenshot per card for repositories granted to the GitHub app. Cards and images stay local; they are not assigned to Muse or run automatically.

The Issues page can load issues from repositories granted to the connected GitHub account. A public issue and selected text file can also be previewed when that repository is granted. Muse suggestions are unverified until checked. The dedicated test workflow requires a local repository and revision allowlist; it is disabled when those settings are absent. For the configured private test case, a connected maintainer can explicitly consent to send the selected issue and source file to Muse; private test harnesses are excluded. Private PR previews are limited to the configured test repository. A maintainer may manually post an anchored comment there after reviewing it.

Automatic reviews support public repositories when enabled in Settings and the local worker (`php artisan review:poll --daemon`) is running. A separate, default-off setting permits one automated inline suggestion per reviewed revision on an opted-in public repository owned by the connected account. The comment is posted as that account and identifies itself as automated. Suggestions can be wrong, and a PR can change between the final check and GitHub accepting a comment. GitHub comment triggers are not supported. Private Muse suggestions on the dedicated test repository carry owner-scoped provenance. Verification of arbitrary generated candidates remains blocked: the experimental trusted oracle is disabled until its isolation is independently established.

Model requests use a local ledger with a default limit of 10 attempts and $0.50 in estimated reservations. The operator may set `PROOFPATCH_MODEL_MAX_ATTEMPTS` between 1 and 100; this does not change the $0.50 limit or set a provider billing cap.

To enable the dedicated test workflow, set `PROOFPATCH_TEST_REPO` to the exact `owner/repository` and `PROOFPATCH_TEST_BASE_COMMIT` to the intended 40-character commit SHA in your private environment. The workflow is limited to the bundled test case and pinned file hashes. Do not commit private settings.

---

## CLI Usage

### Inspect a GitHub Pull Request
Fetch pull request metadata, changed files, and check runs, saving a local snapshot with a digest. The digest is not an independent authenticity guarantee. You can use shorthand handles, full URLs, or run interactively:

```bash
# Shorthand handle
php artisan review:inspect owner/repo#123

# Full URL
php artisan review:inspect https://github.com/owner/repo/pull/123

# Interactive prompt
php artisan review:inspect
```

### Record an Existing Local Snapshot
Record a saved snapshot in SQLite for review:

```bash
php artisan review:record <SNAPSHOT_ID>
```

### Inspect a Review via HTTP API
Query review status, repository metadata, and the saved snapshot digest:

```bash
curl http://127.0.0.1:8000/reviews/<REVIEW_ID>
```

---

## Testing

Run the full PHPUnit test suite:

```bash
php artisan test
# or
./vendor/bin/phpunit
```

Run browser driver detection (if needed):

```bash
composer drivers
```

---

## Project Structure

```text
ProofPatcher/
├── artisan                 # Root Artisan CLI
├── bootstrap/              # Laravel bootstrap & command registration
├── config/                 # Application & SQLite database config
├── database/               # Database migrations & local SQLite store
│   └── migrations/
├── public/                 # Web root (CSS, static assets, index.php)
├── routes/                 # web.php & api.php routes
├── app/                    # Core verification engine & application code
│   ├── Agent/              # Model repair proposal & budgeting
│   ├── Application/        # Controllers & Artisan commands
│   ├── Browser/            # Headless browser probes (Panther)
│   ├── Evidence/           # Receipts & canonical JSON encoding
│   ├── GitHub/             # Pull request reader & snapshot store
│   ├── Patch/              # CSS patch manipulation & validation
│   └── Verifier/           # Worktree isolation & test verifier
├── storage/                # Framework cache, logs & sessions
└── tests/                  # Unit, Integration & Locked regression tests
```

