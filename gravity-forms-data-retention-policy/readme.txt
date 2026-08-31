=== Gravity Forms Data Retention Policy ===
Contributors: alphasys
Tags: gravity forms, data retention, privacy, gdpr, multisite
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.0.1
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enforces a site-wide maximum entry retention policy for every Gravity Forms form.

== Description ==

Gravity Forms Data Retention Policy adds a Retention Policy tab to the main Gravity Forms settings screen. The setting is local to each WordPress site, including each site in a multisite network.

The default policy permanently deletes entries after 28 days. Existing forms are updated when the plugin is initialized and whenever the site policy changes. Forms that exactly matched the previous site policy follow the new setting. New, imported, and subsequently edited forms are checked whenever Gravity Forms saves their metadata.

A form with a custom policy remains independent unless its policy is looser than the new site ceiling. Gravity Forms performs the scheduled cleanup through its native daily retention task.

== Installation ==

1. Install and activate Gravity Forms 2.5.8 or later.
2. Upload the plugin ZIP through Plugins > Add New > Upload Plugin.
3. Activate the plugin for one site or network activate it on multisite.
4. Open Forms > Settings > Retention Policy on each site to review or change its policy.

== Frequently Asked Questions ==

= Does each multisite site have its own policy? =

Yes. Settings use normal site options, so each site's Forms > Settings > Retention Policy tab is independent.

= Can a form use a stricter rule? =

Yes. A form may permanently delete entries sooner, but it cannot exceed the site's day limit or use a weaker disposal action.

= Does this plugin run its own deletion job? =

No. It configures and enforces Gravity Forms' native personal-data retention fields. Gravity Forms performs cleanup through its daily scheduled task.

== Changelog ==

= 1.0.1 =

* Changed inherited forms to follow site policy changes.
* Preserved custom form policies unless they exceed the new site ceiling.

= 1.0.0 =

* Added site-wide retention settings and defaults.
* Added enforcement for existing, new, imported, and updated forms.
* Added single-site and multisite support.
* Added WordPress-native GitHub release updates.
