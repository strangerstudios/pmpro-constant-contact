=== Paid Memberships Pro - Constant Contact Add On ===
Contributors: strangerstudios, flintfromthebasement
Tags: pmpro, constant contact, email marketing, membership, sync
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync your PMPro members with Constant Contact lists and tags.

== Description ==

This plugin integrates Paid Memberships Pro with Constant Contact using the v3 API. Automatically add members to Constant Contact lists and apply tags based on their membership level.

= Features =

* **OAuth 2.0 Authentication** — Secure connection using your Constant Contact application's API Key and Secret (PKCE is used automatically for public clients without a secret).
* **List Management** — Assign Constant Contact lists to each membership level. Members are automatically added when they gain a level and optionally removed when they lose it.
* **Tag Management** — Assign tags per membership level. Only PMPro-controlled tags are modified; manually applied tags are preserved.
* **Non-Member Lists** — Automatically subscribe new users without a membership level to designated lists.
* **Custom Fields** — Membership level ID and name are stored as custom fields on each contact.
* **Profile Sync** — Optionally sync contact data and tags when a user updates their WordPress profile.
* **Background Processing** — Uses PMPro Action Scheduler for non-blocking sync operations.
* **Developer Friendly** — Filter hooks for customizing contact data, custom fields, and tag behavior.

= Hooks =

* `pmprocc_contact_data` — Modify contact data before sending to Constant Contact.
* `pmprocc_custom_fields` — Add or modify custom fields sent with the contact.

== Installation ==

1. Upload the `pmpro-constant-contact` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to Memberships > Constant Contact in your WordPress admin.
4. Enter your Constant Contact API Key (Client ID) and API Secret from the [Constant Contact Developer Portal](https://app.constantcontact.com/pages/dma/portal/).
5. Save settings, then click "Connect to Constant Contact" to authorize.
6. Configure lists and tags for each membership level.

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

Depending on your settings, the member can be removed from the lists associated with their old level and have level-specific tags removed. Non-member lists will be applied if configured.

== Changelog ==

= 2.0 - 2026-03-31 =
* Complete rewrite using Constant Contact v3 API.
* OAuth 2.0 authentication flow (API Key + Secret, with PKCE support for public clients).
* List and tag assignment per membership level.
* Custom fields for membership level data.
* Background sync via PMPro Action Scheduler.
* Non-member list support.
* Debug logging.

= 1.0.3 =
* Legacy version using Constant Contact v2 API (deprecated).
