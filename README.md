# Estate — cPanel real estate platform

A PHP 8.2+ / MySQL application with a responsive ivory-and-forest-green public website and private multi-workspace CRM. No Node server, Composer packages, build process, or WordPress required on cPanel.

## Deploy with cPanel Git Version Control

The repository includes `.cpanel.yml`. In **cPanel → Git Version Control → Manage → Pull or Deploy**, select the `main` branch, click **Update from Remote**, then **Deploy HEAD Commit**. Refresh the Manage screen after pulling if deployment controls have not appeared.

The deployment target is `$HOME/public_html/Estate`, matching `https://elite-noir.com/Estate/` for the normal document-root layout. `scripts/deploy-cpanel.sh` uses an explicit application-file list and preserves `app/config.php`, uploads and database files. It never deploys `.git` or test files. If the repository itself is already in that document directory, the script recognizes it and completes without copying files onto themselves.

The cPanel checkout must have a clean working tree. If **Update from Remote** reports local changes or a non-fast-forward error, preserve those changes and resolve the specific error before deploying; do not delete the database or private configuration. If your domain uses a different document root, adjust the script's target before deployment.

After deployment, open `/Estate/install.php` for first-time setup, or your existing website if already installed. A GitHub push does not itself update cPanel: **Update from Remote → Deploy HEAD Commit** is the pull-deployment workflow.

## cPanel installation

1. Download this repository's ZIP from GitHub (**Code → Download ZIP**) or download the `estate-cpanel` artifact from a successful Actions run.
2. Extract the files into your domain's document root (for example `public_html/estate`). `index.php`, `api.php`, and `.htaccess` must be directly in that folder, not one folder deeper. Enable **Show hidden files** in File Manager and include `.htaccess` files.
3. Select PHP 8.2 or 8.3 with extensions **PDO MySQL, mbstring, fileinfo, cURL**. Use HTTPS. MySQL 5.7+ / MariaDB 10.3+ is required.
4. In cPanel's MySQL Database Wizard create a database and a user, and grant the user all privileges on that database. Use the full cPanel-prefixed names.
5. Visit `https://your-domain/estate/install.php`. The new setup wizard detects your website address automatically.
6. Enter your full cPanel database name, username and database password. Choose your admin name, email and password (at least 12 characters). Click **Install Estate automatically**.
7. The wizard tests MySQL, creates the tables and admin account, generates the installation key, writes private `app/config.php`, prepares storage, and signs you in. No manual file copying, key generation or URL editing is required. Existing configuration is never overwritten and the installer locks after success. Remove `install.php` when finished.
8. Open **Website & settings**, enter your branding/brokerage and enable your profile. Add authorized listings in **Properties**. Approve new agents under **Platform admin** after verification.
9. Set the operator's legal identity, privacy contact, retention policy, and reviewed terms before accepting real customers. The default policy page clearly identifies these outstanding launch settings.

The wizard cannot create a cPanel database/user without cPanel account access. Those are the only hosting resources you create manually. It supports local MySQL (`localhost` or `127.0.0.1`). For a custom host or advanced deployment, copy `app/config.example.php` to `app/config.php`, fill it privately, and use its installation key; that existing setup path is retained. Public installations require HTTPS. Storage is created outside the document root when allowed; if hosting restricts that location, the bundled protected `storage/` folder is used. Keep Apache `.htaccess` protection enabled.

### Application URLs
- Public website: `index.php`
- Agent signup: **For agents → Create your workspace**
- Private CRM: `index.php?view=workspace`
- Interactive sample CRM: `index.php?view=workspace&demo=1`
- Agent website: `index.php?agent=AGENT-SLUG`

## Implemented

- Email/password signup and login, password change, logout, optional emailed password reset.
- Separate agent workspaces; server-scoped database operations. Team members intentionally share the same workspace. Owner-only settings and team controls; platform administrator approval/suspension.
- Public listing search (location, property type, maximum price), property details/gallery, agent directory, agent profile websites, client-account favourites (device-local fallback on general platform pages), seller introduction and enquiry forms.
- CRM contact and lead records, linked activities/tasks/deals/appointments, editable deal stages and drag-and-drop, commission fields, task completion, calendar `.ics` exports, draft campaigns, server-persisted property CRUD, private files, live calculated reports, CSV exports with spreadsheet formula escaping.
- JPEG/PNG/WebP/PDF uploads (10 MB each, 500 MB workspace allowance). A stored image is public only when referenced by a published listing in an approved agent workspace.
- Stripe subscription Checkout, Billing Portal and signed webhook handler. Disabled until configuration; no payment is simulated. Backend checks configured plans when `billing_required=true`.
- CSRF tokens, HttpOnly/SameSite cookies, password hashing, idle-session expiry, reset token hashing/expiry, login/enquiry rate limits, prepared SQL, output escaping, activity audit, and access checks.

## Important scope and launch limits

This is a working initial application, not an independently audited commercial SaaS. Test on your actual cPanel stack before launch. Included integration tests use SQLite for portability; production is intended for MySQL/MariaDB.

- Agent sites now support workspace-only navigation, branding and TXT-verified custom-domain routing; the operator must configure the cPanel domain alias and HTTPS certificate. Wildcard subdomains and unrestricted HTML template editing are not implemented.
- Licensed MLS/IDX listing feeds, unattended Gmail/Outlook mailbox polling, voice calling, e-signatures, complex automations and CSV contact imports remain unconnected. Listing URLs can be previewed into editable drafts; Mailgun inbound routes, outbound email, Twilio lead SMS, opt-in campaign sending and a per-agent client portal are available **only when configured**, as described below.
- Public search uses only agent-uploaded authorized listings. When no listings exist, the public site displays clearly marked design examples with illustrative prices. Demo contacts are loaded only in explicit demo mode and never seeded into the database.
- Teams currently support a workspace owner plus members, maximum five total accounts. There is no granular per-record team assignment/permission matrix.
- Profile approval is performed manually by your administrator; signup does not verify a real estate licence automatically.
- No conversion analytics or traffic numbers are invented. Reports calculate only existing CRM records.
- Native PHP `mail()` must be enabled and tested for password reset, verification emails, direct messages and opt-in campaign batches. Configure SPF/DKIM/DMARC in cPanel. Managed SMTP/email-provider deliverability remains a production deployment responsibility.
- Bundled Unsplash photography, an AI-generated hero, and Google Fonts are used for the design. Replace sample images with your own licensed property media; typography has local fallback fonts.
- Calendar appointments can optionally synchronize through Google or Microsoft OAuth after owner authorization. Local CRM times use the workspace timezone (default America/Vancouver); single .ics exports are still offered. Provider-account connection, token refresh and cross-timezone behaviour require live-host verification.
- Default business prices are proposed launch prices, not an assertion that billing is activated. Annual billing and automatic tax are not configured.

## Stripe setup

1. Create recurring monthly CAD prices for Starter, Professional and Team, matching the prices displayed in `assets/app.js` or update that copy.
2. Put the Stripe secret key and the three price IDs in `app/config.php`.
3. Add webhook endpoint `https://your-domain/estate/api.php?action=stripe_webhook` for `checkout.session.completed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`.
4. Set the webhook signing secret. Enable the Stripe customer portal for plan changes and cancellations. Test everything using test keys first.
5. Set `billing_required=true` only after successful tests. A new workspace gets a 14-day trial; paid active/trialing workspaces can continue editing. Expired workspaces retain sign-in and read/export access; public listings are excluded. Starter supports contacts and properties; additional CRM record creation needs Professional/Team; adding team members requires Team. Existing higher-tier records remain readable/exportable.
6. Review tax treatment, pricing, support, cancellation and refund terms before taking payments. These are operator responsibilities, not automatically configured.

The handler pins Stripe API version 2024-06-20, verifies signatures and timestamp tolerance, deduplicates events, and fetches current subscription state to avoid applying stale event order. Full paid lifecycle testing requires your Stripe test account. References: https://docs.stripe.com/api/checkout/sessions/create and https://docs.stripe.com/webhooks/signature.

## Testing

```sh
find . -name '*.php' -not -path './storage/*' -print0 | xargs -0 -n1 php -l
node --check assets/app.js
node --check assets/features.js
node --check assets/calendar-ui.js
node --check assets/mailbox-ui.js
python3 tests/integration.py
python3 tests/install_wizard.py
```

The integration suite copies the app to a temporary folder, uses a disposable SQLite database and PHP server on port 18766, and tests authentication, CSRF, cross-workspace read/write isolation, approvals, enquiries, team roles, suspension and logout. It never touches your configured production database. GitHub Actions runs the suite and packages a cPanel ZIP.

## Backups and maintenance

Back up the MySQL database, private storage, and private config together. Keep PHP patched, monitor error logs and storage usage, periodically remove old audit/reset/attempt records, and test restoration. Database credentials and config must never be publicly downloadable. If Apache `.htaccess` is unsupported, configure equivalent denial rules before deployment. There is no automated database-upgrade migration framework yet; back up before replacing application versions.

### Bundled image sources

- `assets/hero.webp`: AI-generated architectural illustration for this project.
- `assets/property-1.jpg`: https://images.unsplash.com/photo-1600596542815-ffad4c1539a9
- `assets/property-2.jpg`: https://images.unsplash.com/photo-1613977257363-707ba9348227
- `assets/property-3.jpg`: https://images.unsplash.com/photo-1600047509807-ba8f99d2cdde

These illustrate the design only and do not depict verified listings at the sample locations/prices.

The automatic-wizard CI test runs against a disposable MySQL 8 service. It verifies config generation, subfolder URL detection, automatic admin sign-in, installer locking, and prevention of existing-config overwrite. No production credentials are used.


## Recover administrator access without email

From cPanel Terminal, inside the Git repository, run:

```bash
git pull --ff-only origin main
php scripts/reset-admin.php "$HOME/public_html/Estate"
```

Use the live installation folder if its path differs. The CLI-only tool finds the existing administrator, generates a new random password, re-enables that admin login, invalidates previous sessions/reset tokens, and prints the email and password once in your terminal. It preserves all other users and application records. If there are multiple admins, it lists their emails without modifying anything; pass the intended admin email as the final argument. Sign in in a private window and change the generated password under Website & settings. No email service or web-accessible recovery endpoint is required.


## Agent sites and connected features (upgrade branch)

This release adds a server-scoped public agent-site view. Profile links to **Listings**, **Sell**, **Contact**, favourites and the footer preserve the agent slug. Approved properties from other workspaces do not appear on branded agent pages. A verified custom-domain host receives only its assigned workspace from the public API.

**Full agent branding** is managed under **Website & settings**: brand colour (six-digit HEX), hero photo, logo, headshot, brokerage, biography, email, phone and IANA timezone. Image URLs must be HTTPS or approved uploaded file links. The agent's business name appears in the branded header; the generic agent-directory navigation is removed from their website.

**Custom domains:** Save the domain under Website & settings; add the displayed TXT DNS record at \`_estate-verify.<your-domain>\`; click **Check DNS ownership**. The verified hostname must separately be attached to your Estate deployment in cPanel, its DNS routed to the hosting origin and its TLS certificate provisioned. DNS verification does not do that hosting work automatically. A domain is reserved to only one workspace. Keep your primary \`app_url\` for platform login and calendar OAuth.

**Clients and favourites:** Visitors sign up for a client account on the relevant approved agent website (12+ character password). Property favourites and enquiries are retrieved from server-side records and scoped to that agent, instead of relying on device-local storage. Agent CRM logins are distinct from client logins. Client accounts are per agent, not global single sign-on across unrelated agents. Existing browser-only favourites are not migrated automatically.

**Import listing by link:** In the agent Properties screen, choose **Import listing URL**. The preview accepts HTTPS links to REALTOR.ca, RE/MAX, Royal LePage, Century 21, and Coldwell Banker, and attempts to read publicly exposed Open Graph metadata. The agent confirms legal rights, reviews fields and edits the resulting **unpublished** draft before saving. Remote websites may block preview fetching or omit structured fields. This does **not** constitute an MLS/IDX authorization, automatic live listing feed, permission to republish third-party photography, or real-time listing-status sync. Connect an approved brokerage/board IDX or DDF feed before promising continuously updated inventories.

**Lead email/SMS:** New public enquiries save a workspace CRM contact and attempt an alert email to the agent's configured workspace address. Configure \`mail_enabled\` and \`mail_from\` and test SPF/DKIM/DMARC and delivery for production. Optionally configure \`twilio_sid\`, \`twilio_token\`, \`twilio_from\` in private \`app/config.php\`, and save an E.164 number under SMS lead alerts. Each attempted delivery is logged in \`notification_log\`; failed transport does not discard the CRM lead. Review your hosting logs and provider dashboard.

**Inbox:** CRM now offers an outbound one-to-one **Compose client email** action when PHP mail is enabled, logging sent messages on contact timelines. Optional verified inbound email ingest uses a Mailgun inbound route: set \`mailgun_webhook_signing_key\` in private config, configure a Mailgun route to POST to \`https://YOUR-HOST/Estate/api.php?action=mailgun_inbound\`, and enter the exact recipient address in the workspace settings. The receiver validates the provider's HMAC signature/timestamp, rejects replay, scopes recipient matching to a workspace and creates a contact/activity. This is webhook-based inbound email ingestion; it is not IMAP; Gmail/Outlook OAuth inbox synchronization is described below.


**Connected Gmail / Outlook inbox:** Agents can now authorize an optional Gmail or Microsoft 365 mailbox from **Activity & inbox** using the same provider OAuth client credentials as calendar, with **separate email scopes and callback**. Register \`https://YOUR-HOST/Estate/api.php?action=mailbox_callback\` as an OAuth redirect URL for both providers in addition to the calendar callback. Gmail uses \`gmail.readonly\` and \`gmail.send\`; Microsoft uses \`Mail.Read\`, \`Mail.Send\`, and \`offline_access\`. OAuth tokens are AES-256-GCM encrypted in the database under the private installation key. After connecting, use **Sync latest mail** to import the 12 most recent inbox messages from the past 30 days that match existing CRM contact email addresses. Imported messages appear in the contact activity log, are deduplicated by provider message ID, and can be supplemented by **Send email** through the connected mailbox. Mail is not automatically synced in the background; use a provider-compliant scheduler or signed push ingestion for unattended updates. Gmail restricted scopes may require Google OAuth app review/verification. Outlook work/school tenants can require administrator consent. Enable production use only after live OAuth, token refresh, delivery and mailbox-permission testing. This does not import arbitrary contacts or old mailbox history.

**Campaigns:** Draft campaigns can send a batch of up to 50 emails per click to contacts explicitly marked **Client expressly opted in to marketing emails**. The backend adds signed unsubscribe URLs, enforces an hourly submission cap and tracks each recipient so repeated sends do not duplicate successfully delivered email. The sender must have working email transport. Confirm recipients' consent and applicable CASL compliance; test deliverability and unsubscribe before use. \`mail()\` acceptance is not proof of inbox delivery. This is not a managed bulk-mail provider.

**Calendar sync:** Configure OAuth clients with credentials \`google_oauth_client_id\`, \`google_oauth_client_secret\`, \`microsoft_oauth_client_id\` and \`microsoft_oauth_client_secret\` in the **private** config. Register \`https://YOUR-HOST/Estate/api.php?action=calendar_callback\` as the authorized redirect URI in both providers. Select Connect on the Calendar screen and grant \`calendar.events\` (Google) or \`Calendars.ReadWrite\` (Microsoft). Access/refresh tokens are encrypted at rest with a key derived from the private installation key; backups must preserve that key. Connected calendars receive automatic creates/updates for appointments saved in the CRM. Dates are interpreted using each workspace's IANA timezone, appointments have a default one-hour duration, and cancellation removes linked remote events. Disconnect removes locally saved credentials, **not** remote events or the provider-side authorization grant. Review your provider's application verification/consent requirements and test OAuth and token-refresh flows on the live host.

**Address autocomplete:** Set \`geoapify_key\` in private config. The server requests up to five Geoapify address suggestions for property location input fields; the key never appears in browser code. Observe your provider's API quota and privacy rules.

**Stripe:** Existing signed webhook, Checkout, Billing Portal and billing-required gate are retained, not silently activated. A test Stripe account, price IDs, webhook signing secret, correct \`app_url\`, and end-to-end live-host checks remain required. Before activating \`billing_required\`, verify test Checkout success/failure, trial to paid, renewal, failed payment, cancellation, out-of-order webhooks, replay handling, customer portal and user entitlement for each plan. Stripe live/test secrets are never committed to GitHub.

### Upgrade and review

The first API request that uses a new feature applies **additive idempotent tables** to the existing database; production MySQL account needs \`CREATE TABLE\` permission for the upgrade. Back up database and private storage/config before deployment. The cPanel deployment script now includes the integration modules and frontend assets while preserving private \`app/config.php\`.

Use the feature branch to validate without merging into \`main\` first. Tests cover tenant isolation, login, client favourites, notification logging, invalid provider setup, domain routing and existing Stripe guards. They do **not** substitute for live provider credentials, regulated listing-data licensing, legal policy review, browser/end-to-end testing, or a production security audit.
