# Gravity Forms Data Retention Policy

Author: AlphaSys
Version: 1.0.1
Status: Production

## Purpose

Adds a site-wide retention policy to Gravity Forms and prevents individual forms from using a looser retention rule.

## Key features

- Adds Forms → Settings → Retention Policy.
- Defaults to permanently deleting entries after 28 days.
- Applies the site policy to existing forms when the plugin is initialized or the policy changes.
- Moves forms that exactly matched the previous site policy to the new policy when settings are saved.
- Enforces the policy whenever form metadata is saved, including new and imported forms.
- Preserves form-level policies that are stricter than the site policy.
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

Upload `gravity-forms-data-retention-policy.zip` through Plugins → Add New → Upload Plugin, then activate it. On multisite, the plugin may be network activated; configure each site separately under Forms → Settings → Retention Policy.
