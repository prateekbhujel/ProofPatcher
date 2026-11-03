# GitHub connection and actions

ProofPatcher uses a GitHub App to connect a maintainer to repositories included in its installation. The web app supports GitHub sign-in; the terminal uses device authorization. Both surfaces use the saved connection and selected repository within the current grant.

## Connection

Configure the app's client ID and client secret, enable device authorization for terminal use, and install it on the repositories you want to access. For local web sign-in, register the callback URL for the app's configured `APP_URL`. The [README](../README.md#connect-github) contains setup steps.

GitHub user authorization and installation scope are separate. Being able to see a repository does not by itself authorize every action. A valid connection and the appropriate app permissions are required for each operation.

## Available workflows

- Browse accessible repositories and inspect pull requests, diffs and available checks.
- Request a Muse review of supported public changes. Findings are advisory and tied to the captured revision.
- Opt in to automatic review on public repositories. The local worker polls while it is running; it does not receive webhooks.
- Opt in separately to GitHub bot comments. Bot posting uses a GitHub App installation token and does not silently fall back to the user's token.
- Ask for a public PR review with `@proofpatcher review` while the local mention listener is running.
- Use manual merge and close controls when authorized; those actions require confirmation and recheck the pull request.
- Prepare a draft pull request for the configured, pinned test-repository workflow after its supported verification and server-held publication approval.

Automatic ready, merge and close controls are unavailable. General private pull-request review, arbitrary issue repair and automatic issue-to-PR publication are not supported. A saved repository opt-in does not bypass these limits.

## Permissions and attribution

Configure only the GitHub App permissions needed for the intended actions. Reading metadata and diffs requires read access; creating bot comments or draft pull requests requires the corresponding write permissions. User-token actions and installation-token actions can appear under different GitHub identities. ProofPatcher must not fall back to a user token when a bot action cannot be authorized.

The app treats issue content, diffs, filenames and model output as untrusted data. A Git worktree is not an execution sandbox. Credentials belong in the owner's environment or private settings, never in the repository or an issue comment.
