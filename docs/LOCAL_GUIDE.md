# Running ProofPatcher

## Run locally

### Requirements

- Git and PHP 8.4 or newer, with `gd`, `json`, SQLite, and the extensions required by Laravel and Composer.
- Composer, Node.js 20 or newer, and npm. Composer installs Laravel and the PHP packages; npm installs Inertia, React, TypeScript and the other frontend packages.
- Chrome or Chromium for the visual verification examples.
- Python and a configured Modal account for the optional isolated issue-repair fixture. See [fixture setup](FIXTURE_ORACLE.md#trying-it-locally). Other web and review flows do not need Modal.
- [PIE](https://github.com/php/pie), the [php-terminal extension](https://github.com/prateekbhujel/php-terminal) and an interactive terminal for `php artisan kernel`. A plain menu is available when ANSI color is disabled. The web app does not require PIE or the extension.

For a **new installation**, from the repository directory:

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
touch database/proofpatch.sqlite
php artisan migrate
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Open [http://127.0.0.1:8000](http://127.0.0.1:8000). For an existing installation, keep its `.env` and application key, and back up the SQLite database before migrations. Rolling back task-card migrations deletes those cards or their screenshots.

Run evidence and saved PR snapshots stay in `storage/app/private/proofpatch`, outside the public web directory and excluded from Git by the existing storage rule.

### Connect GitHub

Configure a GitHub App with a client ID and client secret, enable device flow for terminal use, and install the app on the repositories you want to access. For local web sign-in, register `http://127.0.0.1:8000/github/callback` as a callback URL, or use the callback corresponding to your configured `APP_URL`.

Set `PROOFPATCH_GITHUB_CLIENT_ID` and `PROOFPATCH_GITHUB_CLIENT_SECRET` in your private `.env`. Do not commit credentials. Choose **Sign in with GitHub** in the web app; the terminal uses GitHub's device authorization flow.

In **Settings → Provider**, select Baseten or Meta Model API and add that provider's key. Alternatively, set `BASETEN_API_KEY` or `META_MODEL_API_KEY` in your private `.env`, restart the app, and select the corresponding provider in Settings. Baseten is selected by default; adding a Meta key does not switch providers automatically. Laravel does not load `.env` when configuration is cached, so use an uncached configuration or set the variables in the process environment. Never commit keys or put them in screenshots.

Model requests may consume provider credits or incur charges; the local usage ledger is an estimate, not a provider billing limit. An optional `PROOFPATCH_MODEL_MAX_ATTEMPTS` setting limits local request attempts; autopilot also has optional limits in Settings.

### Terminal

```bash
# Open the interactive menu (requires the PHP terminal extension)
php artisan kernel

# Or use individual commands
php artisan github:connect
php artisan kernel:status
php artisan kernel:select-repo --list
php artisan kernel:reviews
```

To search committed code in local Git repositories without sending it to a model:

```bash
php artisan kernel:context 'OrderTotal' --content --repo=/path/to/first --repo=/path/to/second
```

Search uses each repository's current commit by default. It shows at most one matching line per file and 25 files per repository; source lines may contain sensitive data. Omit `--content` to search file paths only. The terminal menu also offers local code search. This is a search tool, not persistent memory or context automatically sent to Muse.

To run a bounded automatic-review cycle, enable review for an authorized **public** repository and then run:

```bash
php artisan kernel:select-repo --enable-review owner/repository
php artisan kernel:autopilot --repo=owner/repository --minutes=1 --max-cycles=1
```

The worker runs only while the local machine and process are running. Automatic review does not process private pull requests. Automatic inline commenting requires its own explicit setting; enabling review alone does not enable comments. Bot posting additionally requires `PROOFPATCH_GITHUB_APP_ID` and an owner-only private key file outside the repository, referenced by `PROOFPATCH_GITHUB_APP_PRIVATE_KEY_PATH`. If the bot is not configured, it does not fall back to posting as the user. Reviews completed before bot setup are not automatically retried for posting.

For a maintainer-requested public PR review, independent of automatic review:

```bash
php artisan kernel:mentions --repo=owner/repository --minutes=10 --publish
```

After the listener reports that it is ready, a maintainer can post `@proofpatcher review` in the PR conversation. The listener checks the requester's access and the PR revision before reviewing and posting. Omit `--publish` to keep results local. This is a bounded, best-effort local listener, not a webhook: it does not process mentions while stopped, and issue or inline-thread mentions are not supported. `@pp review` is also recognized, but may notify an unrelated GitHub account.

## Scope and safeguards

- The visual verifier uses bundled checkout fixtures, not arbitrary selected pull requests. The dedicated issue-to-draft-PR workflow is restricted to its configured test repository and pinned inputs. Set `PROOFPATCH_TEST_REPO` and `PROOFPATCH_TEST_BASE_COMMIT` privately to enable the intended test case.
- Modal can run the configured pinned fixture checks when explicitly enabled. A successful result applies to the tested candidate and cases; it is not proof that arbitrary generated code is safe or correct. General untrusted-candidate verification remains disabled until its test-oracle boundary is established.
- A Git worktree is not a security sandbox. Do not run untrusted repository code on the host merely because it is in a worktree.
- Local task cards and images are not autonomous jobs. The app does not understand attached screenshots in the task-card workflow or automatically solve issues. The dedicated demo issue can be marked eligible for a separate manual fix flow; draft publication requires server-held approval. Automatic merge requires explicit opt-in and strict branch protection; ready and close remain unavailable.
- A snapshot digest or receipt supports integrity checking; it does not independently prove the origin, correctness, or safety of a change.

### Demonstrated workflows

- A fresh Muse proposal for a dedicated private test issue passed the isolated fixture checks and the live publication flow created a draft PR. A separate run found and linked an identical existing draft instead of creating a duplicate.
- Public PR review, multi-issue triage and inline comments were demonstrated using a separate test repository. The checkout example also demonstrates visual checks and rejection of a damaging shortcut.
- Automatic bot merge was exercised against a protected test PR after a completed Muse review and passing required check. Automatic ready and close remain disabled. Task-card screenshots stay local and are not interpreted by Muse.

## Tests

```bash
./vendor/bin/phpunit
python3 -m unittest discover -s tests/Python -p 'test_*.py'
npm run typecheck
node --test tests/ui/*.test.mjs
npm run build
```

`composer drivers` can detect browser drivers for the Panther-based visual checks.
