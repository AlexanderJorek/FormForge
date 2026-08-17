=== FormForge ===
Contributors: alexanderjorek
Tags: forms, form builder, pdf, gdpr, sepa
Requires at least: 7.0.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A drag-and-drop form builder that emails submissions and/or renders them to a cryptographically sealed, tamper-evident PDF.

== Description ==

FormForge lets you build forms with a drag-and-drop admin editor and deliver every submission by email and/or as a generated PDF — without ever writing submission data to a database table. Nothing is retained after the request finishes except what you choose to email or download.

**Field types**

* Text, textarea, email, name, phone, number
* Address, date, time, currency
* Select, radio, checkboxes (multivalue)
* File upload, signature (canvas)
* Rating, slider
* Captcha (Google reCAPTCHA), consent checkbox, GDPR checkbox
* HTML block, section/group, page break
* Hidden post-data field, website (honeypot-friendly)
* SEPA Direct Debit mandate (IBAN with masked input + checksum validation, BIC, account holder, creditor info block, signature capture)

**PDF generation & tamper detection**

Every generated PDF can be embedded with a cryptographic seal. The included PDF Verification tool re-derives the seal from an uploaded PDF and reports byte-level and content-level tampering, including incremental-update ("PDF shadow attack") detection.

**Privacy by design**

FormForge has no custom database tables for form entries and stores no submission data locally. All submitted data is delivered exclusively via email and/or the generated PDF, both of which are under your own server's/mailbox's control.

== Uses Third Party / External Services ==

FormForge's core functionality does not communicate with any external service. Two *optional* fields, only present on forms where you've explicitly added them, do:

**Google reCAPTCHA** (Captcha field)
When you configure a reCAPTCHA site key and secret key in the form settings and add a Captcha field to a form, each form submission's response token is verified server-side against:
`https://www.google.com/recaptcha/api/siteverify`
The token itself (and nothing else from the submission) is sent to Google. This only happens if you explicitly add a Captcha field and configure the keys.
Google reCAPTCHA [Terms of Service](https://policies.google.com/terms) | [Privacy Policy](https://policies.google.com/privacy)

**openiban.com IBAN/BIC lookup** (SEPA field)
If a form contains a SEPA Direct Debit field, every time a site visitor finishes typing a syntactically valid IBAN into that field, their browser automatically sends that IBAN to:
`https://openiban.com/validate/`
to look up and auto-fill the matching BIC. This happens live on the public-facing form for every visitor who fills in the IBAN field on a form containing a SEPA field — not just in the admin editor. No other submission data is sent.
openiban.com [Terms of Service](https://openiban.com/terms.html)

== Installation ==

1. Upload the `form-forge` folder to `/wp-content/plugins/`, or install the plugin zip through the WordPress admin (Plugins → Add New → Upload Plugin).
2. Activate the plugin through the "Plugins" menu in WordPress.
3. On activation you'll be redirected to **FormForge → Settings** to complete a one-time PDF seal key setup. This is required before you can create forms.
4. Once setup is complete, go to **FormForge** in the admin menu to create your first form.
5. Insert the form into a page or post with the provided shortcode, shown on the form's edit screen.

== Frequently Asked Questions ==

= Where is submitted form data stored? =

Nowhere, by design. FormForge has no database table for submissions. Each submission is processed in-memory for the duration of the request and delivered only via email and/or a generated PDF, then discarded.

= Does FormForge send data to any external service? =

Only if a form uses the Captcha field (Google reCAPTCHA) or the SEPA Direct Debit field (openiban.com, called live for every IBAN entered on the public form). See "Uses Third Party / External Services" above. No other part of the plugin makes external requests.

= Why am I asked to complete a setup step right after activating? =

FormForge generates a cryptographic seal key used to make generated PDFs tamper-evident. This one-time setup must be completed before any forms can be created, so the seal key exists from the start rather than being added retroactively.

= Can I customize the generated PDF layout? =

Yes — the PDF Layout Editor (under FormForge → PDF Layout) lets you configure the logo, colors, fonts, margins, and header/footer content used when rendering submissions to PDF.

== Changelog ==

= 1.0.1 =
* Fixed conditional logic not working for checkbox (multivalue) fields.
* Fixed two fields that were arranged side by side in the builder dropping to a stacked, full-width layout on the front end once a conditional logic rule made them visible.
* Translated the Name and Address field sub-field labels in the form builder.
* Smaller plugin package: removed unused developer files bundled inside third-party libraries.

= 1.0.0 =
* Initial public release.
