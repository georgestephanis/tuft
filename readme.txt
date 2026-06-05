=== Tuft ===
Contributors: georgestephanis
Tags: feedback, design, visual feedback, client review, annotations
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Collect visual design feedback directly on the page — visitors click any element, add a note, and optionally capture a screenshot.

== Description ==

Tuft adds a floating button to every page on your site. When clicked, it enters a targeting mode that lets the visitor click any element on the page to annotate it. The plugin captures the DOM selector, click coordinates, viewport dimensions, form field state, and an optional in-browser screenshot, then prompts for a feedback note. Everything is stored locally as a custom post type for admin review.

= Key features =

* **Click-to-annotate** — click the "Feedback" button then click any element. The hovered element highlights so you know exactly what you're selecting.
* **In-browser screenshots** — uses html2canvas (bundled with the plugin, no CDN dependency) to capture the visible viewport at the moment of submission.
* **Context capture** — records the CSS selector, viewport-relative click coordinates, viewport dimensions, and any visible form field values (passwords excluded).
* **Feedback modal** — clean overlay collects the visitor's feedback text. Features a screenshot preview that dynamically centers its crop focal point around your click coordinates, ensuring the target element is never cropped out of view. Logged-in users are not prompted for name or email — their WordPress account details are used automatically. Guest visitors see name and email fields.
* **Local storage** — submissions saved as a `tuft_feedback` custom post type with full metadata and a screenshot attachment.
* **Admin review** — list table with columns for feedback text, source page, element selector, submitter, and screenshot thumbnail. Full detail view with screenshot in the post editor.
* **Alpaca Issue Tracker integration** — if [Alpaca Issue Tracker](https://wordpress.org/plugins/alpaca-issue-tracker/) is installed, each submission is automatically mirrored as a Kanban issue on the Alpaca board. The Tuft admin menu moves under the Alpaca Project Board menu.

= Intended use =

This plugin is designed for **local development and staging environments** where you want clients or team members to leave visual design notes without logging in to a project management tool. The REST endpoint that receives submissions is open by default — review the security notes before deploying to a publicly accessible production site.

= Alpaca Issue Tracker integration =

When [Alpaca Issue Tracker](https://wordpress.org/plugins/alpaca-issue-tracker/) is active alongside Tuft:

* Each submission creates a matching `alpaca_issue` in your board's default (lowest-score) column.
* Issues are tagged with the submitter's browser and the type "Tuft" for easy filtering.
* The Tuft admin menu moves under the **Project Board** submenu, so the Alpaca board becomes the primary triage interface.
* Click any row in the Tuft list to open the full detail view including the screenshot.

== Installation ==

1. Upload the `tuft` folder to `wp-content/plugins/`.
2. Activate **Tuft** through the **Plugins** menu in WordPress.
3. Visit any frontend page — the Tuft FAB button appears ~2/3 down the right edge of the screen.
4. Optionally install and activate **Alpaca Issue Tracker** for Kanban board triage.

No settings page is required. The plugin works immediately on activation.

== Frequently Asked Questions ==

= Who can submit feedback? =

By default, anyone who can visit the page — including logged-out visitors. This is intentional for client review workflows where guests do not have WordPress accounts. See the security notes if you want to restrict submissions to logged-in users.

Logged-in users have a streamlined experience: the name and email fields are hidden in the modal and their WordPress account details are submitted automatically.

= Where are submissions stored? =

In the WordPress database as a custom post type (`tuft_feedback`). You can review them under **Tuft** in the admin menu, or under **Project Board → Tuft** if Alpaca Issue Tracker is active.

= Do screenshots always work? =

html2canvas is bundled with the plugin and served directly from `assets/js/vendor/` — there is no CDN dependency. Screenshots should work in any environment.

In sandboxed environments like WordPress Playground where enqueued stylesheets suffer from cross-origin/CORS restrictions, a built-in Playground helper dynamically inlines stylesheets as `<style>` blocks. This allows html2canvas to access style rules without triggering browser security blocks, ensuring screenshots render fully styled. The only case where a screenshot may be absent is very old browsers that do not support the Canvas API, which is vanishingly rare in practice.

= Can I send submissions somewhere else? =

Yes. Hook into the `tuft_feedback_submitted` action, which fires after a submission is fully saved:

`add_action( 'tuft_feedback_submitted', function ( int $post_id ) {
    $feedback = get_post_meta( $post_id, '_tuft_feedback_text', true );
    $page_url = get_post_meta( $post_id, '_tuft_page_url', true );
    // send a Slack message, open a GitHub issue, etc.
} );`

= Is this safe for production? =

With the default configuration, no — the submission endpoint is open to unauthenticated requests. Before deploying to a public site, change the `permission_callback` in `includes/class-tuft-rest-controller.php` to require a capability:

`'permission_callback' => function() {
    return current_user_can( 'read' );
},`

= Does it work with page builders and custom themes? =

Yes. The plugin injects its UI via `wp_enqueue_scripts` and appends its elements directly to `document.body`, so it works alongside any theme or page builder. The element selector logic ignores elements with `tuft-` prefixed IDs to prevent accidentally annotating the plugin's own UI.

== Screenshots ==

1. The Tuft FAB button (~2/3 down the right edge of a frontend page).
2. Targeting mode — page dims, crosshair cursor, hovered element highlighted.
3. The feedback modal after selecting an element, showing the screenshot thumbnail and form.
4. The Tuft admin list table with screenshot thumbnails.
5. The full detail view for a single submission, including screenshot, selector, coordinates, and submitter info.

== Changelog ==

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

= 1.0.0 =
Initial release. No upgrade steps required.
