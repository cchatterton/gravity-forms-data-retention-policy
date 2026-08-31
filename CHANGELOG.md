# Changelog

All notable changes to Gravity Forms Data Retention Policy are recorded here.

## 1.0.1 - 2026-09-01

- Changed site policy updates so forms that exactly match the previous policy inherit the new policy.
- Preserved custom form policies unless they are looser than the new site policy.

## 1.0.0 - 2026-08-31

- Added a site-wide Gravity Forms retention policy with a default of permanent deletion after 28 days.
- Added enforcement for existing, new, imported, and updated forms while preserving stricter form policies.
- Added single-site and multisite support with independent settings for every site.
- Added WordPress-native updates from public GitHub releases.
