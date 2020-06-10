=== Paid Memberships Pro - Constant Contact Add On ===
Contributors: strangerstudios
Tags: pmpro, paid memberships pro, constant contact, email marketing
Requires at least: 3.4
Tested up to: 5.4.1
Stable tag: 1.0.3

Add users and members to Constant Contact lists based on their membership level.

== Description ==

Subscribe WordPress users and members to your Constant Contact lists.

This plugin offers extended functionality for [membership websites using the Paid Memberships Pro plugin](https://wordpress.org/plugins/paid-memberships-pro/) available for free in the WordPress plugin repository. 

With Paid Memberships Pro installed, you can specify unique lists for each membership level. By default, the integration will merge the user's first and last name if captured. You can send additional user profile details to Constant Contact [using the pmpro_constant_contact_custom_fields filter hook](https://www.paidmembershipspro.com/add-ons/pmpro-constant-contact/#hooks).

The settings page allows the site admin to specify which lists to assign users and members to, plus additional features you may wish to adjust.

= Additional Settings =

* **All Users List:** These are the lists that users will be added to if they do not have a membership level.
* **Unsubscribe on Level Change?:** If set to “No”, users will not be automatically unsubscribed from any lists when they lose a membership level. If set to “Just those managed by PMPro Constant Contact”, users will be unsubscribed from any level lists they are subscribed to when they lose that level, assuming that list is not a All Users list as well. If set to “All”, users will also be unsubscribed from all lists except the All Users List.

= Official Paid Memberships Pro Add On =

This is an official Add On for [Paid Memberships Pro](https://www.paidmembershipspro.com), the most complete member management and membership subscriptions plugin for WordPress.

== Installation ==
This plugin works with and without Paid Memberships Pro installed.

= Download, Install and Activate! =
1. Upload the `pmpro-constant-contact` directory to the `/wp-content/plugins/` directory of your site.
1. Activate the plugin through the 'Plugins' menu in WordPress.
1. Navigate to Settings > PMPro Constant Contact to proceed with setup.

= Configuration and Settings =

**Enter your Constant Contact API Key and Access Token:** Your Constant Contact API Key and Access Token can be found within your Constant Contact account.

After entering these values, save the settings. You will know that your site is connected to your Constant Contact account when the lists appear on the settings page. Continue with the setup by assigning User or Member Lists and reviewing the additional settings.

For full documentation on all settings, please visit the [Constant Contact Integration Add On documentation page at Paid Memberships Pro](https://www.paidmembershipspro.com/add-ons/pmpro-mailchimp-integration/). 

== Frequently Asked Questions ==

= I found a bug in the plugin. =

Please post it in the issues section of GitHub and we'll fix it as soon as we can. Thanks for helping. https://github.com/strangerstudios/pmpro-constant-contact/issues

= I need help installing, configuring, or customizing the plugin. =

Please visit our premium support site at http://www.paidmembershipspro.com for more documentation and our support forums.

== Screenshots ==

1. General settings for all members/subscribers list and unsubscribe rules.
2. Membership-level specific list subscription settings.

== Changelog ==

= 1.0.3 =
* Updating Constant Contact Signup Link and new signups offer.

= 1.0.2 =
* Overhaul by Michael Roufa.
* Added options to only unsub from PMPro-related lists. (Thanks, Michael Roufa)
* Fixed bug where updates on the profile page led to members being added to all CC lists. (Thanks, Michael Roufa)

= 1.0.1 =
* Fixed warnings when PMPro is not also installed.

= 1.0 =
* Released to the WordPress repository.

= .1.1 =
* Showing API errors on settings page.
