<?php

declare(strict_types=1);

include __DIR__ . '/../layout/header.php';
?>

<h1 class="font-serif text-3xl text-brand-ink">Help</h1>
<p class="mt-2 text-sm text-brand-muted max-w-2xl">
    How to connect an AI assistant to Rolo, what it can do once connected,
    and why nothing changes without your say-so.
</p>

<div class="mt-8 max-w-2xl space-y-10">

    <section>
        <h2 class="font-serif text-xl text-brand-ink">What the connector is</h2>
        <p class="mt-2 text-sm text-brand-inksoft leading-relaxed">
            Rolo speaks MCP (Model Context Protocol), the language AI assistants
            use to work with outside tools. Once connected, your assistant can
            look things up in Rolo for you and suggest changes. Suggestions land
            on the <a href="/proposals" class="text-brand-red hover:underline">Approvals</a>
            page and wait for a human. Nothing an assistant does touches the CRM
            until someone approves it there.
        </p>
    </section>

    <section>
        <h2 class="font-serif text-xl text-brand-ink">Connect Claude</h2>
        <ol class="mt-2 ml-5 list-decimal text-sm text-brand-inksoft leading-relaxed space-y-1.5">
            <li>You need your private connector link. It looks like
                <code class="text-xs bg-brand-cream2 rounded px-1.5 py-0.5">
                    https://crm.example.com/mcp/&lt;your&nbsp;key&gt;</code>
                and the administrator sends it by direct message. Treat it like a password:
                it IS your key to the CRM, so never post it anywhere public.</li>
            <li>In Claude, open <strong>Settings &gt; Connectors</strong> and click
                <strong>Add custom connector</strong>.</li>
            <li>Name it <strong>Rolo CRM</strong>, paste your private link, and click
                <strong>Add</strong>.</li>
            <li>In any chat, click the <strong>+</strong> button, choose
                <strong>Connectors</strong>, and switch Rolo on.</li>
        </ol>
    </section>

    <section>
        <h2 class="font-serif text-xl text-brand-ink">Connect Superhuman</h2>
        <p class="mt-2 text-sm text-brand-inksoft leading-relaxed">
            Superhuman Go connects through a custom MCP connector too. It needs
            the server URL
            <code class="text-xs bg-brand-cream2 rounded px-1.5 py-0.5">https://crm.example.com/mcp</code>,
            the root domain <code class="text-xs bg-brand-cream2 rounded px-1.5 py-0.5">crm.example.com</code>,
            and Bearer Token authentication with your key. Ask the administrator to help set
            up the connector pack the first time.
        </p>
    </section>

    <section>
        <h2 class="font-serif text-xl text-brand-ink">Things to try</h2>
        <ul class="mt-2 ml-5 list-disc text-sm text-brand-inksoft leading-relaxed space-y-1.5">
            <li>&ldquo;Who&rsquo;s overdue in Rolo this week?&rdquo;</li>
            <li>&ldquo;What&rsquo;s my history with [contact]? Anything I should know before our call?&rdquo;</li>
            <li>&ldquo;Log a coffee meeting with [contact] today. We discussed the Nashville event.
                Follow up next Friday to send the deck.&rdquo;</li>
            <li>&ldquo;Add [company] as an organization in Rolo.&rdquo;</li>
            <li>&ldquo;Tag everyone at [organization] with PDA-Nashville.&rdquo;</li>
        </ul>
        <p class="mt-3 text-sm text-brand-muted leading-relaxed">
            Anything that logs, creates, or edits shows up on the Approvals page
            for review. Each card shows exactly what would change and what it
            does to the follow-up schedule, so the worst case is a proposal you
            reject with one click.
        </p>
    </section>

    <section>
        <h2 class="font-serif text-xl text-brand-ink">Troubleshooting</h2>
        <ul class="mt-2 ml-5 list-disc text-sm text-brand-inksoft leading-relaxed space-y-1.5">
            <li><strong>Claude says it can&rsquo;t see a tool</strong> that should exist
                (creating organizations, follow-up notes): start a new chat. Claude
                caches the tool list per conversation. If that doesn&rsquo;t fix it,
                toggle the Rolo connector off and on in Settings &gt; Connectors.</li>
            <li><strong>&ldquo;That token is not recognized&rdquo;</strong>: your key has
                changed. Ask the administrator for a fresh link, then update the connector.</li>
            <li><strong>A proposal was rejected with an error</strong>: the change
                clashed with the live data (for example, the contact was deleted in
                the meantime). Ask the assistant to look up the current state and
                propose again.</li>
            <li><strong>You think the link leaked</strong>: tell the administrator and the key is
                rotated in minutes. Old links stop working immediately.</li>
        </ul>
    </section>

</div>

<?php include __DIR__ . '/../layout/footer.php';
