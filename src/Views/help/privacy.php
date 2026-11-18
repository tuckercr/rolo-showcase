<?php

/**
 * Public privacy page. The Google OAuth consent screen links here, so it is
 * readable without signing in and spells out how Google user data (sign-in
 * identity, read-only calendar) is used — wording Google's verification
 * checks look for lives in the "Google user data" section.
 */

declare(strict_types=1);

include __DIR__ . '/../layout/header.php';
?>

<div class="max-w-3xl">
    <h1 class="font-serif text-3xl text-brand-ink">Privacy</h1>
    <p class="mt-2 text-sm text-brand-muted">Last updated November 17, 2026</p>

    <div class="mt-6 space-y-6 text-sm text-brand-inksoft leading-relaxed">
        <section>
            <h2 class="text-lg font-semibold text-brand-ink">What Rolo is</h2>
            <p class="mt-2">
                Rolo is the private customer relationship manager (CRM) used by the
                consultancy's team. It is not a public service: signing in
                requires a Google account on a short allowlist, and everything in it
                is business relationship data entered by the team.
            </p>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-brand-ink">What we store</h2>
            <p class="mt-2">
                Contact and organization records (names, email addresses, phone
                numbers, notes), a history of interactions the team logs, and files
                the team attaches. This information is entered by the team, stored in
                our own database at our hosting provider, and used only to manage
                the consultancy's business relationships.
            </p>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-brand-ink">Google user data</h2>
            <p class="mt-2">
                <strong>Sign-in.</strong> We use Google Sign-In to authenticate the
                team. From your Google account we receive your name, email address
                and account identifier, and we use them only to sign you in and to
                attribute changes you make. Access is limited to allowlisted
                accounts.
            </p>
            <p class="mt-2">
                <strong>Calendar (optional).</strong> A team member can connect their
                own Google Calendar from the settings page. Access is read-only. We
                read the day's events (titles, times and attendee email addresses)
                solely to suggest, in a private Slack message to that same person,
                logging meetings they had with people already in the CRM. Event
                details are not stored; the only things we keep are the connection's
                refresh token and the connected account's email address, and both are
                deleted immediately when the calendar is disconnected. Rolo never
                writes to your calendar, and nothing from your calendar is visible to
                anyone but you.
            </p>
            <p class="mt-2">
                Google user data is never sold, never shared with third parties,
                never used for advertising, and never used to train AI models.
                Rolo's use of information received from Google APIs adheres to the
                <a class="text-brand-red hover:underline"
                   href="https://developers.google.com/terms/api-services-user-data-policy">Google
                API Services User Data Policy</a>, including the Limited Use
                requirements.
            </p>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-brand-ink">Slack</h2>
            <p class="mt-2">
                Rolo's Slack bot sends reminders and summaries to the team's own
                Slack workspace and responds to commands and button presses from
                allowlisted team members. It records when a notice was sent (to avoid
                duplicates) and the CRM changes a person asks it to make; it does not
                read or store Slack conversations.
            </p>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-brand-ink">Cookies and tracking</h2>
            <p class="mt-2">
                Rolo sets one session cookie so you stay signed in. There are no
                analytics, no advertising trackers and no third-party scripts beyond
                the libraries the pages need to render.
            </p>
        </section>

        <section>
            <h2 class="text-lg font-semibold text-brand-ink">Retention and deletion</h2>
            <p class="mt-2">
                CRM records are kept for as long as they are useful to the team and
                can be deleted in the app. Disconnecting a calendar deletes its
                stored credentials right away. To ask about data we hold, or to have
                something corrected or removed, email
                <a class="text-brand-red hover:underline"
                   href="mailto:colin@example.com">colin@example.com</a>.
            </p>
        </section>
    </div>
</div>

<?php include __DIR__ . '/../layout/footer.php';
