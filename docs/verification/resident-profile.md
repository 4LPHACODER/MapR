# Resident profile redesign verification

Branch: `redesign/resident-profile`.

The profile now shows a compact identity strip, read-only account information, and an editable contact panel. All information stays visible without the old section filter. Sign out is an explicit action. Profile controls and photo overlays omit outer focus rings while preserving focus through borders, fills, and menu highlights. The photo menu uses PrimeVue; profile preview motion is limited to occasional pointer selection.

## Checks

- Production build: `docker exec mapr-vite-1 npm run build` passed. Vite retains its large-chunk warning.
- Backend: `ResidentProfilePhotoTest` and `ResidentReportTest` passed: 14 tests, 420 assertions.
- Frontend Design Premium strict audit passed with zero findings; JSON saved to `/tmp/mapr-profile-audit.json` during this session.
- `DESIGN.md` lint passed with zero errors and six existing orphan-token warnings.
- `git diff --check` passed. No frontend formatter or typecheck command is configured.
- Chromium rendered the actual resident application, shared PrimeVue preset, and application styles with controlled API responses. Desktop at 1440 × 1000 and phone at 390 × 844 were visually inspected; screenshots were saved in `/tmp/mapr-profile-desktop.png` and `/tmp/mapr-profile-phone.png`.
- Contact input measured 32px with no outline style or box shadow. Save/discard, validation error focus, retained input, disabled controls while saving, success feedback, and cancelled unsaved-change confirmation passed.
- Photo menu Escape restored trigger focus. Pointer selection opened an animated preview; keyboard selection opened instantly. Cancel and upload succeeded. Photo removal failure remained visible after dismissal.
- Preview motion produced CSS opacity and transform transitions. Reduced-motion preview retained no spatial transformation. Preview images remain mounted until dismissal completes.
- Loading, failed initial load, retry, unassigned barangay guidance, long names, and long unbroken email values passed. Phone document width did not exceed viewport width.
- axe checks on the profile returned no WCAG A/AA findings in the tested state. The implementation fixes the hidden file input label and compact success-message contrast.

## Limits

Browser checks use fixture responses rather than an authenticated server session. They verify the rendered frontend and interactions, while the backend tests cover server permissions, validation, and persistence separately. This is not a full application accessibility audit or physical-device motion review. Motion can be inspected at slower playback in browser DevTools before release.

## Impeccable polish

Removed the repeated page heading and introduction, camera instructions, role badge, decorative section icons, and redundant signed-in footer. The resident name now leads the content. The profile uses a 60rem maximum width, an 18/14/13/12px type scale with explicit line heights, quieter metadata, consistent row spacing, and a smaller reserved feedback area. Existing small controls, focus treatment, and motion remain intact.

The Chromium interaction suite passed again with desktop and phone screenshots, long-content and unassigned-barangay checks, photo-menu keyboard operation, save/error/retry paths, and zero axe findings on the profile. Impeccable's detector returned no findings. Browser checks still use controlled API responses, with the limits described above.
