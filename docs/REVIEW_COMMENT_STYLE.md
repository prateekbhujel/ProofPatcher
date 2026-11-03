# Review comment style

ProofPatcher presents concise, actionable findings. Comments should explain the concern and its effect, then suggest a next step when the evidence supports one. A maintainer decides what to do with the finding.

## Labels

| Finding status | Label | Use |
| --- | --- | --- |
| `concern` | `issue` | A possible problem to investigate or fix |
| `note` | `nitpick` | A non-blocking improvement |
| `approval` | `praise` | A positive observation |

A concern is marked blocking only when the review verdict is `request_changes`. A finding with an unknown verdict does not claim a blocking state.

## GitHub and the workbench

An inline comment includes the label, a short subject and any useful explanation. If a valid replacement is available, a GitHub suggestion block may be included. Comments are sanitized before posting; the publisher may omit an invalid suggestion.

The workbench shows the finding, its label and available suggestion. A line reference identifies where the model found an issue in the captured patch; it is not itself a test result.

Formatting lives in `app/GitHub/ReviewCommentFormatter.php` and `resources/js/Components/diff/FindingCard.tsx`. Posting is separately authorized and uses the configured GitHub App bot.
