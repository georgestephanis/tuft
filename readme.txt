=== Tuft Feedback ===
Contributors: georgestephanis
Tags: feedback, design, visual feedback, client review, annotations
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.2.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Collect visual design feedback directly on the page — visitors click any element, annotate a screenshot, and add a note, all without leaving the browser.

== Description ==

Tuft adds a floating button to every page on your site. When clicked, it enters a targeting mode that lets the visitor click any element on the page to annotate it. The plugin captures the DOM selector, click coordinates, viewport dimensions, form field state, and an in-browser screenshot, then presents a drawing toolbar so the reviewer can mark up the screenshot before typing their feedback. Everything is stored locally as a custom post type for admin review.

= Key features =

* **Click-to-annotate** — click the "Feedback" button then click any element. The hovered element highlights so you know exactly what you're selecting.
* **In-browser screenshots** — uses html2canvas (bundled with the plugin, no CDN dependency) to capture the visible viewport at the moment of submission.
* **Freehand canvas annotation** — a drawing toolbar below the screenshot preview lets reviewers draw freehand strokes or rectangles, undo the last stroke, or clear all marks. Annotations are baked into the submitted JPEG on the client.
* **Context capture** — records the CSS selector, viewport-relative click coordinates, viewport dimensions, and any visible form field values (passwords excluded).
* **Feedback modal** — clean overlay collects the visitor's feedback text alongside the annotated screenshot. Logged-in users are not prompted for name or email — their WordPress account details are used automatically. Guest visitors see name and email fields.
* **Widget visibility controls** — choose who sees the feedback button: everyone (including logged-out visitors), logged-in users only, editors and above, or administrators only. Configured under **Settings → Tuft Feedback**.
* **Submission rate limiting** — cap submissions per IP address per hour to protect against spam. Set to 0 to disable. Tracked via post meta queries.
* **Built-in notifications** — email a comma-separated list of addresses and/or POST a JSON payload to any number of webhook URLs on every submission. Works with Slack incoming webhooks, Discord, Teams, Zapier, Make, and any HTTP endpoint.
* **Local storage** — submissions saved as a `tuft_feedback` custom post type with full metadata and a screenshot attachment.
* **Admin review** — list table with columns for feedback text, source page, element selector, submitter, and screenshot thumbnail. Full detail view with screenshot in the post editor.
* **Alpaca Issue Tracker integration** — if [Alpaca Issue Tracker](https://wordpress.org/plugins/alpaca-issue-tracker/) is installed, each submission is automatically mirrored as a Kanban issue on the Alpaca board. The Tuft admin menu moves under the Alpaca Project Board menu.

= Intended use =

This plugin is designed for **local development and staging environments** where you want clients or team members to leave visual design notes without logging in to a project management tool. The widget visibility setting under **Settings → Tuft Feedback** controls who sees the button; the REST endpoint that receives submissions is open by default — review the security notes before deploying to a publicly accessible production site.

= Alpaca Issue Tracker integration =

When [Alpaca Issue Tracker](https://wordpress.org/plugins/alpaca-issue-tracker/) is active alongside Tuft:

* Each submission creates a matching `alpaca_issue` in your board's default (lowest-score) column.
* Issues are tagged with the submitter's browser and the type "Tuft" for easy filtering.
* The Tuft admin menu moves under the **Project Board** submenu, so the Alpaca board becomes the primary triage interface.
* Click any row in the Tuft list to open the full detail view including the annotated screenshot.

== Installation ==

1. Upload the `tuft-feedback` folder to `wp-content/plugins/`.
2. Activate **Tuft** through the **Plugins** menu in WordPress.
3. Visit any frontend page — the Tuft FAB button appears ~2/3 down the right edge of the screen.
4. Configure who sees the button, rate limiting, and notification emails/webhooks under **Settings → Tuft Feedback**.
5. Optionally install and activate **Alpaca Issue Tracker** for Kanban board triage.

== Frequently Asked Questions ==

= Who can submit feedback? =

This is configurable under **Settings → Tuft Feedback → Widget visibility**. The options are:

* **Everyone** — all visitors, including logged-out guests (useful for anonymous client review)
* **Logged-in users only** — any authenticated WordPress user (the default)
* **Editors and above** — users with the `edit_others_posts` capability
* **Administrators only** — users with the `manage_options` capability

Logged-in users always have a streamlined experience: the name and email fields are hidden in the modal and their WordPress account details are submitted automatically.

= Where are submissions stored? =

In the WordPress database as a custom post type (`tuft_feedback`). You can review them under **Tuft Feedback** in the admin menu, or under **Project Board → Tuft Feedback** if Alpaca Issue Tracker is active.

= Do screenshots always work? =

html2canvas is bundled with the plugin and served directly from `assets/js/vendor/` — there is no CDN dependency. Screenshots should work in any environment.

In sandboxed environments like WordPress Playground where enqueued stylesheets suffer from cross-origin/CORS restrictions, a built-in Playground helper dynamically inlines stylesheets as `<style>` blocks. This allows html2canvas to access style rules without triggering browser security blocks, ensuring screenshots render fully styled. The only case where a screenshot may be absent is very old browsers that do not support the Canvas API, which is vanishingly rare in practice.

= Can I get notified when someone submits feedback? =

Yes, without writing any code. Under **Settings → Tuft Feedback → Notifications**:

* **Notify email(s)** — enter a comma-separated list of email addresses. A plain-text notification is sent to each address on every submission.
* **Webhook URL(s)** — enter one URL per line. A JSON payload is POSTed to each URL on every submission. The payload is compatible with Slack incoming webhooks (it includes a `text` field), Discord, Teams, Zapier, Make, and any custom HTTP endpoint.

For fully custom integrations, hook into the `tuft_feedback_submitted` action:

`add_action( 'tuft_feedback_submitted', function ( int $post_id ) {
    $feedback = get_post_meta( $post_id, '_tuft_feedback_text', true );
    $page_url = get_post_meta( $post_id, '_tuft_page_url', true );
    // create a GitHub issue, update a tracker, etc.
} );`

= Is this safe for production? =

The widget visibility setting controls both the frontend button **and** the REST submission endpoint — they are kept in sync automatically. Setting visibility to **Logged-in users only**, **Editors and above**, or **Administrators only** will block unauthenticated API submissions with a 401/403 before any data is written. No additional configuration is required.

= Does it work with page builders and custom themes? =

Yes. The plugin injects its UI via `wp_enqueue_scripts` and appends its elements directly to `document.body`, so it works alongside any theme or page builder. The element selector logic ignores elements with `tuft-` prefixed IDs to prevent accidentally annotating the plugin's own UI.

== Screenshots ==

1. The Tuft FAB button (~2/3 down the right edge of a frontend page).
2. Targeting mode — page dims, crosshair cursor, hovered element highlighted.
3. The feedback modal after selecting an element, showing the annotated screenshot canvas and drawing toolbar.
4. The Tuft admin list table with screenshot thumbnails.
5. The full detail view for a single submission, including screenshot, selector, coordinates, and submitter info.
6. The Settings → Tuft Feedback page with visibility controls, rate limiting, and notification options.

== Changelog ==

= 1.2.0 =
* **REST API permission enforcement** — the `/tuft/v1/submit` endpoint now mirrors the widget visibility setting; requests from users who don't meet the configured access level are rejected with a proper 401/403 before any data is written.
* **Admin stylesheet** — meta box styles extracted from inline `<style>` output to `assets/css/admin.css`, enqueued only on `tuft_feedback` screens. Resolves a plugin directory guideline violation.
* **Formatting toolchain** — added `npm run format` (and `format:js`, `format:css`, `format:php`) backed by `wp-scripts format`, stylelint `--fix`, and `phpcbf`.
* Settings page: visibility field labels now pass through `wp_kses` with an explicit allowlist instead of unescaped output.

= 1.1.0 =
* **Freehand canvas annotation** — a drawing toolbar (pen, rectangle, undo, clear) is shown below the screenshot preview in the feedback modal. Strokes are baked into the submitted JPEG; no server-side overlay is applied.
* **Widget visibility settings** — new Settings → Tuft Feedback page; choose to show the feedback button to everyone, logged-in users, editors and above, or administrators only. Role-gated options display approximate user counts.
* **Submission rate limiting** — max submissions per IP per hour, tracked via post meta (`_tuft_submitter_ip`) rather than transients. Set to 0 to disable.
* **Email notifications** — configure comma-separated recipient addresses to receive a plain-text email on every submission.
* **Webhook notifications** — configure one or more webhook URLs to receive a JSON POST on every submission. The payload is compatible with Slack, Discord, Teams, Zapier, Make, and any HTTP endpoint.
* Admin menu labels renamed from "Tuft" to "Tuft Feedback" for clarity.
* Removed server-side SVG annotation overlay (made redundant by client-side canvas annotation).

= 1.0.0 =
* Initial release.
* Click-to-annotate with element highlighting and crosshair targeting mode.
* Element bounding box captured and shown as a dashed rectangle in screenshot annotations.
* In-browser viewport screenshot via html2canvas, annotated with a spotlight and crosshair at the click point.
* Form field state capture (passwords excluded).
* Feedback modal: name/email fields shown to guests, hidden for logged-in users whose account details are used automatically.
* `tuft_feedback` custom post type with admin list table and detail meta box.
* `tuft_feedback_submitted` action hook for custom integrations.
* Alpaca Issue Tracker integration: auto-creates mirrored `alpaca_issue` with context comment (including annotated screenshot), moves admin menu under Project Board.

== Upgrade Notice ==

= 1.2.0 =
Security hardening: the REST submission endpoint now enforces the widget visibility setting. No database changes; existing submissions are unaffected.

= 1.1.0 =
Adds canvas drawing, visibility controls, rate limiting, and built-in email/webhook notifications. No database changes; existing submissions are unaffected.

= 1.0.0 =
Initial release. No upgrade steps required.
