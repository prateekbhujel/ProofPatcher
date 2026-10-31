# Audio transcription evaluation: Whisper fallback vs Muse Voice Transcribe

Date: 2026-10-31. Evaluation only — no live audio calls were made and no keys were used.

## Current state

`VoiceDictationButton` transcribes maintainer voice notes in the browser. When the
network transcription path is unavailable it falls back to on-device Whisper
(`b299528`). That fallback is offline and private, but single-language oriented
and bound to what the local model files support.

## Candidate: Muse Voice Transcribe

Meta's real-time audio perception model (Meta Superintelligence Labs, launched
2026-09-01), available through the Meta Model API — the same `api.meta.ai`
provider family the app already supports for text.

- Streaming transcription plus endpoint detection and speaker diarization
  (20+ speakers) from one model.
- Trained on 70+ languages, 25 extensively validated at launch, including
  Hindi, Tamil, Telugu, Kannada, and Malayalam, with code-switching.
- Public API price of $0.18 per audio hour ($3 per 1,000 audio-minutes).

Sources: Meta launch coverage via VentureBeat, 9to5Mac, Slator, and The Rundown
AI (all September 2026). Not yet verified from the Model API reference itself.

## Fit for ProofPatcher

- The multilingual story matches the co-maintainer vision: reporters and
  maintainers can dictate in their own language.
- It reuses the existing Meta provider rails: owner-only key storage,
  provider selection in Settings, and the request-budget ledger pattern
  (audio minutes would need their own unit next to token micros).

## Open questions before implementation

1. Exact API contract (endpoint path, streaming transport, auth header, audio
   formats) from the Meta Model API reference — not confirmed from press
   coverage alone.
2. Privacy: audio leaves the device, unlike Whisper. Needs explicit per-use
   consent and a clear retention statement before any maintainer audio is sent.
3. Cost accounting: audio minutes need ledger support distinct from token
   reservations, with a separate cap.

## Recommendation

Keep the offline Whisper fallback as the default. Add Muse Voice Transcribe as
an opt-in provider-gated path later: available only when the Meta provider and
key are configured, with explicit consent per dictation and audio-minute
budgeting. Next step is a docs-only spike confirming the endpoint contract.
