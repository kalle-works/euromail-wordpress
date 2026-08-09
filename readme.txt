=== Euromail – SMTP & Email API ===
Contributors: kalleworks
Tags: smtp, transactional email, email api, wp_mail, deliverability
Requires at least: 5.7
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Routes wp_mail() through the euromail.dev transactional email API, with an SMTP fallback, a delivery log, and webhook-driven delivery tracking.

== Description ==

Euromail replaces WordPress's default mail sending with the [euromail.dev](https://euromail.dev) transactional email API — no server-side sendmail, no unauthenticated PHP mail() calls landing in spam. Every email your site already sends through `wp_mail()` (order confirmations, password resets, comment notifications, contact form submissions, anything) goes out through Euromail automatically, with no code changes required in the plugins that call `wp_mail()`.

**API sending, with an SMTP fallback**

Send through the Euromail API for the best deliverability, or through plain SMTP (including Euromail's own SMTP relay) if you prefer. Turn on automatic fallback and the plugin retries through the other backend if the primary one fails, so a transient API or SMTP outage doesn't mean a lost password-reset email.

**A real delivery log**

Every send is recorded: recipient, subject, backend used, status, and (optionally) the message body itself. Failed sends are retried automatically on a schedule you don't have to think about, and can be resent manually from the log with one click.

**Delivery tracking via webhooks**

Configure a webhook secret and Euromail will push delivery, open, click, bounce, and complaint events straight into your site's log in real time — cryptographically signed, so a spoofed request can never fake a status change.

**Site Health integration**

A dedicated Site Health check confirms Euromail is configured and able to send, so a broken API key or an incomplete SMTP configuration shows up where WordPress administrators already look for site problems.

**Privacy-conscious by default**

Message bodies are optional to store, retention is configurable, and a single setting removes all Euromail data — including the delivery log — when the plugin is uninstalled.

= Requirements =

* An [euromail.dev](https://euromail.dev) account and API key for API sending, delivery tracking, and domain verification status — or just an SMTP host/username/password if you only want the SMTP backend.
* PHP 7.4 or newer.

== Installation ==

1. Install and activate the plugin through the WordPress admin, or upload the `euromail` folder to `/wp-content/plugins/` and activate it from the Plugins screen.
2. Go to **Euromail → Settings**.
3. Choose a sending backend:
   * **Euromail API** — paste your API key. Use the "Verify key" button to confirm it works before saving.
   * **SMTP** — enter your SMTP host, port, and credentials, or click "Use Euromail SMTP relay" to fill in Euromail's own relay settings automatically.
4. Optionally enable **Fallback** so the other backend is tried automatically if the primary one fails.
5. Send a test email from **Euromail → Send Test** to confirm everything works end to end.
6. To receive real-time delivery/open/click/bounce events, set a **Webhook secret** in Settings and paste the webhook URL shown there (plus the same secret) into your Euromail dashboard.

== Frequently Asked Questions ==

= Does this replace every plugin that sends email? =

It replaces how those emails are *sent*, not how they're composed. Any plugin or theme that calls WordPress's standard `wp_mail()` function — which is nearly all of them — is automatically routed through Euromail. Nothing else needs to change.

= What happens if I don't configure anything? =

Euromail steps out of the way. An unconfigured site sends mail exactly as it did before the plugin was installed.

= Is my message content stored? =

Only if you turn on "Store message body" in Settings, and only until the send reaches a final state (sent or failed) — successful sends have attachment content stripped from what's kept. You control the log retention period, and can delete all stored data (settings and log rows) on uninstall with a single checkbox.

= Can I see whether an email was actually delivered, opened, or clicked? =

Yes, if you're sending through the Euromail API and have configured a webhook secret. Delivery, open, click, bounce, and complaint events arrive as signed webhooks and update the log entry's status and timeline in real time. You can also click "Refresh status" on any API-sent log entry to pull the latest status on demand.

= What happens to a failed send? =

It's queued for an automatic retry, with backoff between attempts. You can also see it immediately in the delivery log and resend it manually at any time.

== Screenshots ==

1. Settings — choose your backend, configure fallback, and set up webhooks.
2. Delivery log — every send, its status, and one-click resend.
3. Log entry detail — the full event timeline for a single email.

== Changelog ==

= 0.1.0 =
* Initial release: API and SMTP sending with automatic fallback, a delivery log with retry and resend, webhook-driven delivery tracking, a Site Health check, and a domains status page.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
