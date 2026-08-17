## What

<!-- One paragraph: what changes and why. Link the issue. -->

## Checklist

- [ ] `npm run lint:php` and `npm run test:php` pass locally
- [ ] `npm run build` succeeds (admin + widget)
- [ ] Verified in wp-env (`npx wp-env start`, http://localhost:8990) — describe what you clicked/curled
- [ ] No secrets, keys or PII in the diff; new options/tables mirrored in `uninstall.php`
- [ ] User-facing strings are translatable (`__()` with the `agentyllo` text domain)
- [ ] readme.txt / External Services updated if a new outbound request was added
