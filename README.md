# Gravity Forms Data Retention Policy

Author: AlphaSys
Version: 1.2.1
Status: Production

## Purpose

Adds a site-wide retention policy to Gravity Forms and prevents individual forms from using a looser retention rule.

## Key features

- Adds Forms → Settings → Retention Policy.
- Defaults to permanently deleting entries after 28 days.
- Saves policy configuration without changing forms during plugin activation or settings updates.
- Enforces a two-step read-only test followed by explicit policy activation; activation cannot run without the matching test.
- Reports forms to be changed, saved entries already due for removal, permanent deletions, and affected entries with File Upload or Post Image values. The UI calls out that Save and Continue drafts are also subject to Gravity Forms retention but are not included in the entry total.
- Shows an Apply defaults checkbox for every affected form, selected by default. Unchecked forms are retained as site-level policy exceptions and remain outside ongoing enforcement.
- Moves forms that exactly matched the previously applied site policy to the new policy when the tested policy is activated.
- Enforces the policy whenever form metadata is saved, including new and imported forms.
- Preserves form-level policies that are stricter than the site policy.
- Scans content, metadata, widgets, theme settings, and active theme files, then reports every active form and its detected usage locations.
- Preselects only forms with no detected usage for deactivation and requires the scan before the deactivation action can run.
- Stores settings independently on each site in a multisite network.
- Supports single-site or network activation.
- Delivers updates through native WordPress update screens from GitHub releases.

## Policy enforcement

The site policy is the maximum permitted retention. A form may permanently delete entries sooner, but it cannot retain entries longer than the site limit or use a weaker action. Gravity Forms performs the actual trashing or permanent deletion through its daily scheduled task.

## Requirements

- WordPress 6.0 or later.
- PHP 8.1 or later.
- Gravity Forms 2.5.8 or later.

## Installation

Upload `gravity-forms-data-retention-policy.zip` through Plugins → Add New → Upload Plugin, then activate it. On multisite, the plugin may be network activated; configure, test, and explicitly activate the policy separately under Forms → Settings → Retention Policy on each site.
