# Showcase assets (Center Stage)

ProofPatcher's launch imagery is produced with
[Center Stage](https://reactbits.dev/tools/center-stage) from real
captures of the running app. No mockups: every shot starts from the
actual console.

## Capture list

1. **Console home** — connected, a repository selected, findings listed.
   Source: `http://127.0.0.1:8000/` in a 1440×900 browser window.
2. **Workbench** — an issue open with a verified candidate and receipt.
   Source: `http://127.0.0.1:8000/issues`.
3. **Connect screen** — signed out, the framed sign-in panel.
   Source: sign out, then `http://127.0.0.1:8000/issues`.
4. **Terminal** — `php artisan kernel` dashboard and `kernel:status`.
   Source: screen recording of the real shell (for the GIF/video export).

## Recipe (per shot)

1. Capture at 2× scale, light and dark theme.
2. In Center Stage: browser preset for web shots, terminal window for
   the shell; tilt ≤ 6°, depth of field off (text must stay readable),
   shadow soft, background = the app's own `--bg` tone, never a gradient.
3. Export 4K PNG for stills, GIF for the terminal flow.
4. Save as `docs/assets/showcase-<name>.png` and reference from README.

Keep text legible: if a reviewer can't read the shot at README width,
retake it flatter. Product first, polish second.
