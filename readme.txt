=== Paid Memberships Pro - Constant Contact Add On ===
Contributors: strangerstudios, flintfromthebasement
Tags: pmpro, constant contact, email marketing, membership, sync
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync your PMPro members to a Constant Contact list with tags assigned per membership level.

== Description ==

This plugin integrates Paid Memberships Pro with Constant Contact using the v3 API. Members are automatically added to a Constant Contact list of your choice and tagged based on their membership level.

= Features =

* **OAuth 2.0 Authentication** — Secure connection using your Constant Contact application's API Key and Secret (PKCE is used automatically for public clients without a secret).
* **Tags per Level** — Assign tags for each membership level to segment your members. Only PMPro-controlled tags are modified; manually applied tags are preserved.
* **Profile Sync** — Optionally sync contact data and tags when a user updates their WordPress profile.
* **Background Processing** — Uses PMPro Action Scheduler for non-blocking sync operations.
* **Developer Friendly** — Filter hooks for customizing contact data, custom fields, and tag behavior.

= Hooks =

* `pmprocc_contact_data` — Modify contact data before sending to Constant Contact (e.g. add custom fields).
* `pmprocc_contact_tag_ids` — Modify the set of tag IDs assigned to a contact based on their membership levels.
* `pmprocc_controlled_tag_ids` — Modify the set of tag IDs the plugin is allowed to add and remove.

== Installation ==

1. Upload the `pmpro-constant-contact` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to Memberships > Constant Contact in your WordPress admin.
4. Enter your Constant Contact API Key (Client ID) and API Secret from the [Constant Contact Developer Portal](https://app.constantcontact.com/pages/dma/portal/).
5. Save settings, then click "Connect to Constant Contact" to authorize.
6. Choose the list that members should be added to and assign tags for each membership level.

== Frequently Asked Questions ==

= Where do I get my API Key and Secret? =

1. Go to the [Constant Contact Developer Portal](https://app.constantcontact.com/pages/dma/portal/).
2. Create a new application.
3. Set the Redirect URI to your WordPress admin URL (shown on the settings page).
4. Copy the API Key (Client ID) and the generated Client Secret into the plugin settings. Note: Constant Contact only displays the secret once when it is generated, so copy it right away.

= Does this sync existing members? =

The plugin syncs members when their membership level changes or when they update their profile. To sync existing members, you can trigger a membership level or profile update for those users.

= Will this remove tags I applied manually in Constant Contact? =

No. The plugin only manages tags that are mapped to PMPro membership levels. Any tags you apply manually in Constant Contact are left untouched.

= What happens when a member cancels? =

Level-specific tags are removed (if tag removal is enabled). The contact remains on your member list so you can continue to reach them with win-back campaigns; remove or unsubscribe contacts directly in Constant Contact if desired. Note that Constant Contact bills by active contact count.

= Why one list with tags instead of a list per level? =

Constant Contact tags are account-wide and can be used to filter recipients when sending an email, so a single list plus per-level tags keeps members segmented without maintaining multiple lists. Note: accounts with more than 10,000 contacts cannot filter by tag at send time and must use a custom segment instead (active custom segments are limited on the Lite and Standard plans).

== Changelog ==

= 2.0 - 2026-07-10 =
* FEATURE: Complete rewrite of the Add On using the Constant Contact v3 API. #17 (@flintfromthebasement, @dparker1005)
* FEATURE: Automatically tag members in Constant Contact based on their membership levels.
* FEATURE: Connect securely to Constant Contact with OAuth 2.0, including PKCE support for applications without a client secret.
* FEATURE: Sync members in the background using the PMPro Action Scheduler for faster checkouts.
* FEATURE: Enable debug logging to troubleshoot syncs and API activity.
* ENHANCEMENT: Contacts in Constant Contact are automatically kept in sync when a member changes their email address in WordPress.

= 1.0.3 =
* Legacy version using Constant Contact v2 API (deprecated).
