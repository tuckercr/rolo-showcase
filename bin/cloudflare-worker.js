/**
 * Cloudflare Worker "rolo-daily-summary": the dumb heartbeat plus a
 * dead-server watchdog.
 *
 * This file is the reference copy; the deployed code lives in the
 * Cloudflare dashboard (Workers & Pages -> rolo-daily-summary -> Edit
 * code) and must be pasted there by hand after any change here.
 *
 * Cron triggers (4, all UTC, legacy pair at :05): 13:05, 14:05, 21:00,
 * 22:00. Each one POSTs /cron/daily-summary; every notice on the server
 * gates itself (send hour, weekday, dedupe), so this Worker needs no
 * schedule logic — see docs/PROJECT_NOTES.md.
 *
 * Watchdog: when the tick does not answer 2xx (server down, PHP fatal,
 * expired cert, DNS), nothing server-side can report it, so the Worker
 * DMs Slack directly.
 *
 * Variables (Settings -> Variables and Secrets):
 *   CRON_SECRET      (secret) - same value as the server .env
 *   SLACK_BOT_TOKEN  (secret) - the bot's xoxb token, for the alert DM
 *   SLACK_OPS_USER   (text)   - Slack member id to DM, e.g. U0123ABCD
 */

export default {
  async scheduled(event, env, ctx) {
    const url = 'https://crm.example.com/cron/daily-summary';
    let failure = null;

    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: { Authorization: `Bearer ${env.CRON_SECRET}` },
        signal: AbortSignal.timeout(30000),
      });

      if (!res.ok) {
        const body = (await res.text()).slice(0, 200);
        failure = `HTTP ${res.status}${body ? `: ${body}` : ''}`;
      }
    } catch (e) {
      failure = e && e.message ? e.message : 'fetch failed';
    }

    if (failure && env.SLACK_BOT_TOKEN && env.SLACK_OPS_USER) {
      ctx.waitUntil(fetch('https://slack.com/api/chat.postMessage', {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${env.SLACK_BOT_TOKEN}`,
          'Content-Type': 'application/json; charset=utf-8',
        },
        body: JSON.stringify({
          channel: env.SLACK_OPS_USER,
          text: `:rotating_light: Rolo cron tick failed: ${failure} (${url})`,
        }),
      }));
    }
  },

  // Direct browser hits on the Worker have nothing to do; the real work
  // only runs from the cron triggers above.
  async fetch() {
    return new Response('rolo-daily-summary: scheduled worker', { status: 200 });
  },
};
