# Changelog

All notable changes to Gravity Forms Data Retention Policy are recorded here.

## 1.1.0 - 2026-09-03

- Removed automatic form-policy changes during WordPress plugin activation.
- Added inactive and active policy status with explicit test and activation actions.
- Added a pre-activation report showing forms to be changed, saved entries already due for removal, permanent deletions, and affected entries containing File Upload or Post Image values.
- Added a conservative site-usage scan and a separate action to deactivate active forms with no detected embed.
- Added policy deactivation without reverting existing form settings.

## 1.0.1 - 2026-09-01

- Changed site policy updates so forms that exactly match the previous policy inherit the new policy.
- Preserved custom form policies unless they are looser than the new site policy.

## 1.0.0 - 2026-08-31

- Added a site-wide Gravity Forms retention policy with a default of permanent deletion after 28 days.
- Added enforcement for existing, new, imported, and updated forms while preserving stricter form policies.
- Added single-site and multisite support with independent settings for every site.
- Added WordPress-native updates from public GitHub releases.
