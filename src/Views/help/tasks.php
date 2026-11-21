<?php

/**
 * The tasks user guide. Static copy: what a task is, how it differs from a
 * follow-up, and how to use tasks on the web, in Slack and through an AI
 * assistant. Keep it in step with TaskController, SlackController's task
 * commands and the MCP task tools when any of them change.
 */

declare(strict_types=1);

include __DIR__ . '/../layout/header.php';

$code = 'text-xs bg-brand-cream2 rounded px-1.5 py-0.5 whitespace-nowrap';
$h2 = 'font-serif text-xl text-brand-ink';
$p = 'mt-2 text-sm text-brand-inksoft leading-relaxed';
$ul = 'mt-2 ml-5 list-disc text-sm text-brand-inksoft leading-relaxed space-y-1.5';
$th = 'text-left font-medium text-brand-muted px-4 py-2 border-b border-brand-sand';
$td = 'px-4 py-2 border-b border-brand-cream2 align-top';
?>

<p class="text-sm"><a href="/help" class="text-brand-red hover:underline">&larr; Help</a></p>
<h1 class="mt-2 font-serif text-3xl text-brand-ink">Tasks guide</h1>
<p class="mt-2 text-sm text-brand-muted max-w-2xl">
    Tasks are Rolo&rsquo;s to-do list: one-off things to get done, each with a
    checkbox. This page covers what they are for, and how to use them on the
    web, in Slack and through your AI assistant.
</p>

<nav class="mt-4 max-w-2xl text-sm text-brand-inksoft">
    <span class="text-brand-muted">On this page:</span>
    <a href="#what" class="text-brand-red hover:underline">Tasks or follow-ups</a> &middot;
    <a href="#add" class="text-brand-red hover:underline">Adding</a> &middot;
    <a href="#done" class="text-brand-red hover:underline">Ticking off</a> &middot;
    <a href="#owner" class="text-brand-red hover:underline">Mine and shared</a> &middot;
    <a href="#where" class="text-brand-red hover:underline">Where they show up</a> &middot;
    <a href="#slack" class="text-brand-red hover:underline">Slack</a> &middot;
    <a href="#assistant" class="text-brand-red hover:underline">AI assistant</a> &middot;
    <a href="#know" class="text-brand-red hover:underline">Good to know</a>
</nav>

<div class="mt-8 max-w-2xl space-y-10">

    <section id="what">
        <h2 class="<?= $h2 ?>">Task or follow-up?</h2>
        <p class="<?= $p ?>">
            Rolo has always tracked <strong>follow-ups</strong>: every contact has one
            next follow-up date, and logging an interaction moves it forward on the
            rhythm you set for that stage. That keeps relationships warm.
        </p>
        <p class="<?= $p ?>">
            A <strong>task</strong> is different. It is a single thing to do, it may or
            may not be about a contact, and when you tick it, it is finished. Ticking a
            task never moves anybody&rsquo;s follow-up date.
        </p>
        <div class="mt-3 bg-white rounded-lg shadow overflow-x-auto">
            <table class="w-full text-sm text-brand-inksoft">
                <thead>
                    <tr>
                        <th class="<?= $th ?>"></th>
                        <th class="<?= $th ?>">Follow-up</th>
                        <th class="<?= $th ?>">Task</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="<?= $td ?> text-brand-muted">What it is</td>
                        <td class="<?= $td ?>">When to be in touch with someone next</td>
                        <td class="<?= $td ?>">One thing that needs doing</td>
                    </tr>
                    <tr>
                        <td class="<?= $td ?> text-brand-muted">How many</td>
                        <td class="<?= $td ?>">One per contact</td>
                        <td class="<?= $td ?>">As many as you like, with or without a contact</td>
                    </tr>
                    <tr>
                        <td class="<?= $td ?> text-brand-muted">How it ends</td>
                        <td class="<?= $td ?>">Log an interaction and it reschedules itself</td>
                        <td class="<?= $td ?>">Tick the checkbox and it is done</td>
                    </tr>
                    <tr>
                        <td class="<?= $td ?> text-brand-muted">Examples</td>
                        <td class="<?= $td ?>">Check in with Maria next month</td>
                        <td class="<?= $td ?>">Send Maria the Q1 proposal. Intro Steve to the data
                            team. Prep the webinar slides. Renew the PO box.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="<?= $p ?>">
            A quick test: if the answer to &ldquo;and after that?&rdquo; is &ldquo;get back in
            touch again later&rdquo;, it is a follow-up. If the answer is &ldquo;nothing, it is
            done&rdquo;, it is a task. One contact can have both at once, and often will.
        </p>
    </section>

    <section id="add">
        <h2 class="<?= $h2 ?>">Adding a task</h2>
        <p class="<?= $p ?>">
            Every place tasks appear has the same one-line form: type what needs doing,
            optionally pick a due date, and press <strong>Add</strong>. Only the title is
            required.
        </p>
        <ul class="<?= $ul ?>">
            <li><strong>Dashboard:</strong> the Tasks section at the top. Use the
                &ldquo;No contact&rdquo; menu to attach the task to someone, or leave it for a
                task that is not about anyone.</li>
            <li><strong>A contact&rsquo;s page:</strong> the Tasks card on the left. Anything
                added there is about that contact.</li>
            <li><strong>An organization&rsquo;s page:</strong> the Tasks section. Use this when
                the task is about the organization as a whole and no one person.</li>
            <li><strong>The <a href="/tasks" class="text-brand-red hover:underline">Tasks</a>
                page:</strong> the full list, where you can also choose the owner as you add.</li>
        </ul>
        <p class="<?= $p ?>">
            A task you add is yours unless you say otherwise. To change anything later
            (title, notes, due date, owner, who it is about), click the task&rsquo;s title to
            open its edit form.
        </p>
        <p class="<?= $p ?>">
            A task can be about a contact or about an organization, not both. A task about a
            contact already shows that contact&rsquo;s organization, and it appears on the
            organization&rsquo;s page too.
        </p>
    </section>

    <section id="done">
        <h2 class="<?= $h2 ?>">Ticking off, undoing, deleting</h2>
        <ul class="<?= $ul ?>">
            <li><strong>Tick the checkbox</strong> and the task is done. Rolo records who
                ticked it and when.</li>
            <li><strong>Changed your mind?</strong> A task you just ticked stays on the
                dashboard for a day, crossed out. Click its checkbox again to put it back.
                Older ones are under <strong>Done</strong> on the Tasks page, and can be
                reopened the same way.</li>
            <li><strong>Delete</strong> is on the edit form, for tasks added by mistake. It
                is permanent, so for anything you actually did, tick it instead. Done tasks
                are kept as a record.</li>
        </ul>
    </section>

    <section id="owner">
        <h2 class="<?= $h2 ?>">Mine, shared, and Everyone</h2>
        <p class="<?= $p ?>">
            Tasks use the same ownership idea as contacts. Every task has an
            <strong>owner</strong>, or is <strong>Shared</strong>.
        </p>
        <ul class="<?= $ul ?>">
            <li><strong>Mine</strong> (the default view) shows the tasks you own plus the
                shared ones. Your morning email and your Slack lists follow the same rule.</li>
            <li><strong>Shared</strong> tasks show up for both of you, with a small
                &ldquo;Shared&rdquo; tag. Whoever gets to it first ticks it.</li>
            <li><strong>Everyone</strong> shows the whole list, with the other person&rsquo;s
                name tagged on their tasks.</li>
        </ul>
        <p class="<?= $p ?>">
            Ownership is a filter, not a lock. You can always see, edit, tick or reassign
            any task. To hand one over, open it and change the owner.
        </p>
    </section>

    <section id="where">
        <h2 class="<?= $h2 ?>">Where tasks show up</h2>
        <ul class="<?= $ul ?>">
            <li><strong>Dashboard:</strong> tasks that are overdue, due in the next 7 days,
                or have no date. Tasks dated further out are counted (&ldquo;Plus 2 due
                later&rdquo;) and listed on the Tasks page.</li>
            <li><strong>Tasks page:</strong> everything, grouped as Overdue, Today, Upcoming
                and No date, plus the Done list.</li>
            <li><strong>Contact and organization pages:</strong> every open task about that
                record, whoever owns it, and the last few that were done.</li>
            <li><strong>Morning email:</strong> a &ldquo;Your tasks&rdquo; block with what is
                overdue or due today. A day with tasks but no follow-ups still sends.</li>
            <li><strong>Evening Slack check-in:</strong> the same overdue and due-today tasks,
                each with a Done button.</li>
        </ul>
        <p class="<?= $p ?>">
            Overdue tasks are shown in bold with how late they are. Nothing happens
            automatically to a late task: it stays on your list until you tick it, move
            its date, or delete it.
        </p>
    </section>

    <section id="slack">
        <h2 class="<?= $h2 ?>">In Slack</h2>
        <p class="<?= $p ?>">Two commands, usable in any channel or DM. Only you see the reply.</p>

        <h3 class="mt-4 text-sm font-semibold text-brand-ink">Add a task</h3>
        <p class="<?= $p ?>">
            <code class="<?= $code ?>">/rolo task &lt;what&gt; [when]</code>
        </p>
        <ul class="<?= $ul ?>">
            <li><code class="<?= $code ?>">/rolo task Renew the PO box</code>
                adds a task with no due date.</li>
            <li><code class="<?= $code ?>">/rolo task Send the Q1 proposal friday</code>
                is due this coming Friday.</li>
            <li><code class="<?= $code ?>">/rolo task Book the venue by 2026-12-01</code>
                is due on that date.</li>
            <li><code class="<?= $code ?>">/rolo task for steve: Send the pricing one-pager tomorrow</code>
                attaches it to Steve. If more than one contact matches the name, Rolo
                lists them and adds nothing, so you can try again with a fuller name.</li>
        </ul>
        <p class="<?= $p ?>">
            <strong>When</strong> is read from the end of the line and can be
            <em>today</em>, <em>tomorrow</em>, a weekday (<em>monday</em>, <em>fri</em>),
            <em>next week</em>, <em>in 3 days</em>, <em>in 2 weeks</em>, or a date written
            as <em>2026-12-01</em>. You can put <em>by</em>, <em>on</em> or <em>due</em> in
            front of it. A weekday always means the next one, so &ldquo;friday&rdquo; typed
            on a Friday is a week away. Use &ldquo;today&rdquo; for today.
        </p>
        <p class="<?= $p ?>">
            Rolo only takes those last words as a due date when the rest still reads as a
            task. The short days <em>mon</em>, <em>wed</em>, <em>sat</em> and <em>sun</em>
            are also ordinary words, so they need <em>by</em>, <em>on</em> or <em>due</em>
            in front (<em>by sat</em>). And when the date is part of the sentence, as in
            &ldquo;Plan for next week&rdquo; or &ldquo;Call the venue about Friday&rdquo;,
            the whole line is kept as the task with no due date.
        </p>
        <p class="<?= $p ?>">
            If the task ends in a date word that Rolo would still take as the due date,
            wrap it in quotes:
            <code class="<?= $code ?>">/rolo task "Team lunch friday"</code>.
            A date can still follow the closing quote, as in
            <code class="<?= $code ?>">/rolo task "Team lunch friday" by wednesday</code>.
        </p>
        <p class="<?= $p ?>">
            Tasks added from Slack are yours. Rolo replies with the task as it was saved,
            so you can see the due date it understood, with Done and Open buttons.
        </p>

        <h3 class="mt-4 text-sm font-semibold text-brand-ink">See and tick your tasks</h3>
        <p class="<?= $p ?>">
            <code class="<?= $code ?>">/rolo tasks</code> lists your open tasks (yours plus
            shared), soonest first. Each has a <strong>Done</strong> button, and after you
            press it an <strong>Undo</strong> button in case it was the wrong one.
            <strong>Open</strong> takes you to the task in Rolo.
        </p>
        <p class="<?= $p ?>">
            <code class="<?= $code ?>">/rolo who &lt;name&gt;</code> now also lists that
            contact&rsquo;s open tasks, so you see what you owe someone before you call.
        </p>
    </section>

    <section id="assistant">
        <h2 class="<?= $h2 ?>">With your AI assistant</h2>
        <p class="<?= $p ?>">
            If Claude (or another assistant) is connected to Rolo, it can read your tasks
            and propose changes to them. See <a href="/help" class="text-brand-red hover:underline">Help</a>
            for how to connect one.
        </p>
        <ul class="<?= $ul ?>">
            <li>&ldquo;What&rsquo;s on my task list this week?&rdquo;</li>
            <li>&ldquo;Do I owe Steve Marsh anything?&rdquo;</li>
            <li>&ldquo;Add a task to send Maria the Q1 proposal by Friday.&rdquo;</li>
            <li>&ldquo;Push the webinar slides task to next Wednesday.&rdquo;</li>
            <li>&ldquo;Mark the PO box task as done.&rdquo;</li>
            <li>&ldquo;Add a shared task for both of us to review the pricing page.&rdquo;</li>
        </ul>
        <p class="<?= $p ?>">
            Reading happens straight away. Anything that adds, changes or completes a task
            is a <strong>proposal</strong>: it waits on the
            <a href="/proposals" class="text-brand-red hover:underline">Approvals</a> page (and
            arrives in Slack with Approve and Reject buttons) until one of you says yes. That
            is the same rule as everything else an assistant does in Rolo. A task your
            assistant proposes is yours unless you ask for someone else or for shared.
        </p>
        <p class="<?= $p ?>">
            If the assistant says it cannot find the task tools, start a new chat. It
            remembers the list of tools from when the conversation began.
        </p>
    </section>

    <section id="know">
        <h2 class="<?= $h2 ?>">Good to know</h2>
        <ul class="<?= $ul ?>">
            <li><strong>Tasks and follow-ups never interfere.</strong> Ticking a task about
                Maria does not count as contacting her. If you spoke to her, log the
                interaction as usual.</li>
            <li><strong>Want a reminder to do it during a call?</strong> Keep using the
                follow-up note on a logged interaction for &ldquo;what to raise next time we
                talk&rdquo;. Use a task when it has to happen whether or not you talk.</li>
            <li><strong>No due date is fine.</strong> Undated tasks sit at the bottom of the
                list and stay out of the morning email and the Slack check-in.</li>
            <li><strong>Trashing a contact hides its tasks</strong> along with it. Restore the
                contact and they come back.</li>
            <li><strong>Notes</strong> on a task show under its title in the lists, cut to one
                line. Open the task to read the rest.</li>
            <li><strong>The trail is kept.</strong> Each task shows who added it, who last
                changed it, and who ticked it.</li>
        </ul>
    </section>

</div>

<?php include __DIR__ . '/../layout/footer.php';
