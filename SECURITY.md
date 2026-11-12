# Security Policy

Security matters for a plugin that connects a WordPress installation to a video hosting account.

## Supported release status

The v1.0.4-beta.1 candidate is **beta software** and is **not production-tested**. Install it on a staging site only. Back up your WordPress database and files before testing migrations.

## Reporting a vulnerability

Please **do not post tokens, API keys, passwords, private URLs, full database dumps, or exploit details in public Issues**.

If GitHub offers **Report a vulnerability** in this repository's **Security** tab, use that private reporting channel. Otherwise, use the [official PeeStream website](https://peestream.com) to request a private security contact method before sharing sensitive details.

Include the affected plugin version, WordPress and DooPlay versions (if relevant), PHP version, impact, and minimal reproduction steps. Redact credentials and personal data.

## Safe testing

Use disposable staging content and separate test API credentials. Never run unreviewed migration or rollback tests against a production database.
