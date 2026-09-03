=== Gravity Forms Data Retention Policy ===
Contributors: alphasys
Tags: gravity forms, data retention, privacy, gdpr, multisite
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.1.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enforces a site-wide maximum entry retention policy for every Gravity Forms form.

== Description ==

Gravity Forms Data Retention Policy adds a Retention Policy tab to the main Gravity Forms settings screen. The setting is local to each WordPress site, including each site in a multisite network.

The default configuration permanently deletes entries after 28 days, but installing or activating the WordPress plugin does not change forms. Administrators save the configuration, run a read-only impact test, review the affected forms, saved-entry counts, permanent deletions, and File Upload or Post Image counts, then explicitly activate the tested policy. The report notes that Save and Continue drafts are also subject to Gravity Forms retention but are not included in the saved-entry total.

When a tested policy is activated, forms matching the previously applied policy follow the new setting. A form with a custom policy remains independent unless its policy is looser than the new site ceiling. Gravity Forms performs the scheduled cleanup through its native daily retention task.

The settings page can also scan active forms for references in site content, post metadata, common widgets, theme settings, and active theme files. A separate action deactivates the listed forms after a fresh scan. Dynamic plugin code and external applications cannot be conclusively detected.

== Installation ==

1. Install and activate Gravity Forms 2.5.8 or later.
2. Upload the plugin ZIP through Plugins > Add New > Upload Plugin.
3. Activate the plugin for one site or network activate it on multisite.
4. Open Forms > Settings > Retention Policy on each site, save the configuration, run Test Policy, review the report, and activate the tested policy.

== Frequently Asked Questions ==

= Does each multisite site have its own policy? =

Yes. Settings use normal site options, so each site's Forms > Settings > Retention Policy tab is independent.

= Can a form use a stricter rule? =

Yes. A form may permanently delete entries sooner, but it cannot exceed the site's day limit or use a weaker disposal action.

= Does this plugin run its own deletion job? =

No. It configures and enforces Gravity Forms' native personal-data retention fields. Gravity Forms performs cleanup through its daily scheduled task.

== Changelog ==

= 1.1.0 =

* Removed automatic form changes during plugin activation.
* Added read-only policy testing, explicit activation, and active/inactive status.
* Added entry and file-upload impact reporting.
* Added conservative unused-form scanning and deactivation.

= 1.0.1 =

* Changed inherited forms to follow site policy changes.
* Preserved custom form policies unless they exceed the new site ceiling.

= 1.0.0 =

* Added site-wide retention settings and defaults.
* Added enforcement for existing, new, imported, and updated forms.
* Added single-site and multisite support.
* Added WordPress-native GitHub release updates.
