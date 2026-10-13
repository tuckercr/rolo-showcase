<?php

declare(strict_types=1);

use App\Support\AvatarTone;
use App\Support\View;

/**
 * Daily summary email. Inline styles only — email clients ignore stylesheets.
 *
 * @var string $appUrl
 * @var list<array<string, mixed>> $overdue
 * @var list<array<string, mixed>> $dueToday
 * @var list<array<string, mixed>> $yesterdayActivities
 * @var array<string, string> $activityTypes
 * @var string $todayLabel
 * @var string $yesterdayLabel
 * @var array<int, string> $photoCids contact id => inline-attachment cid
 */

$photoCids = $photoCids ?? [];

$avatarCell = static function (int $contactId, string $name) use ($photoCids): string {
    $cell = '<td style="width:40px;padding:8px 0 8px 12px;border-bottom:1px solid #DCD3C2;'
        . 'vertical-align:middle;">';

    if (isset($photoCids[$contactId])) {
        return $cell . '<img src="cid:' . View::e($photoCids[$contactId]) . '" width="28" height="28"'
            . ' alt="" style="display:block;border-radius:50%;">' . '</td>';
    }

    $tone = AvatarTone::for($name);

    return $cell . '<span style="display:inline-block;width:28px;height:28px;line-height:28px;'
        . 'border-radius:50%;text-align:center;font-size:11px;font-weight:bold;'
        . 'background-color:' . $tone['bg'] . ';color:' . $tone['text'] . ';">'
        . View::e(AvatarTone::initials($name)) . '</span></td>';
};

$rowStyle = 'padding:8px 12px;border-bottom:1px solid #DCD3C2;font-size:14px;color:#3A3630;';
$headingStyle = 'font-size:13px;text-transform:uppercase;letter-spacing:0.05em;'
    . 'margin:28px 0 8px;font-weight:bold;';

$contactRows = static function (array $contacts) use ($appUrl, $rowStyle, $avatarCell): string {
    $html = '';

    foreach ($contacts as $contact) {
        $org = (string) ($contact['organization_name'] ?? '');
        $html .= '<tr>'
            . $avatarCell((int) $contact['id'], (string) $contact['name'])
            . '<td style="' . $rowStyle . '">'
            . '<a href="' . View::e($appUrl . '/contacts/' . (int) $contact['id']) . '"'
            . ' style="color:#C1272D;font-weight:bold;text-decoration:none;">'
            . View::e((string) $contact['name']) . '</a>'
            . ($org !== '' ? ' <span style="color:#8A857C;">· ' . View::e($org) . '</span>' : '')
            . (($contact['follow_up_note'] ?? '') !== ''
                ? '<br><span style="color:#5A5650;font-size:12px;">'
                    . View::e((string) $contact['follow_up_note']) . '</span>'
                : '')
            . '</td>'
            . '<td style="' . $rowStyle . '">' . View::e((string) $contact['relationship_status']) . '</td>'
            . '<td style="' . $rowStyle . 'white-space:nowrap;">'
            . View::e((string) $contact['next_touch_date']) . '</td>'
            . '</tr>';
    }

    return $html;
};
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Rolo daily summary</title></head>
<body style="margin:0;padding:0;background-color:#F3EFE7;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"
       style="background-color:#F3EFE7;padding:24px 0;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0"
       style="background-color:#ffffff;border-radius:12px;overflow:hidden;
              font-family:Helvetica,Arial,sans-serif;">

    <tr><td style="background-color:#111111;padding:20px 28px;">
        <span style="color:#ffffff;font-size:18px;font-weight:bold;">Rolo</span>
        <span style="color:#8A857C;font-size:13px;"> &middot; <?= View::e($todayLabel) ?></span>
    </td></tr>

    <tr><td style="padding:20px 28px 0;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            <td style="font-size:14px;color:#3A3630;">
                Have you logged what you did yesterday?
            </td>
            <td align="right" style="white-space:nowrap;">
                <a href="<?= View::e($appUrl) ?>"
                   style="display:inline-block;background-color:#C1272D;color:#ffffff;
                          padding:8px 14px;border-radius:6px;font-size:13px;
                          font-weight:bold;text-decoration:none;">
                    Open Rolo &rarr;
                </a>
            </td>
        </tr></table>
    </td></tr>

    <tr><td style="padding:0 28px 28px;">

        <?php if ($dueToday !== []) : ?>
            <h2 style="<?= $headingStyle ?>color:#5A5650;">
                Due today (<?= count($dueToday) ?>)
            </h2>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <?= $contactRows($dueToday) ?>
            </table>
        <?php endif ?>

        <h2 style="<?= $headingStyle ?>color:#C1272D;">
            Overdue (<?= count($overdue) ?>)
        </h2>
        <?php if ($overdue === []) : ?>
            <p style="font-size:14px;color:#8A857C;margin:0;">Nothing overdue. &#127881;</p>
        <?php else : ?>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <?= $contactRows($overdue) ?>
            </table>
        <?php endif ?>

        <h2 style="<?= $headingStyle ?>color:#5A5650;">
            Logged <?= View::e($yesterdayLabel) ?> (<?= count($yesterdayActivities) ?>)
        </h2>
        <?php if ($yesterdayActivities === []) : ?>
            <p style="font-size:14px;color:#8A857C;margin:0;">
                No interactions were logged yesterday. Anything to add?
            </p>
        <?php else : ?>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <?php foreach ($yesterdayActivities as $activity) : ?>
                    <tr>
                    <?= $avatarCell(
                        (int) ($activity['contact_id'] ?? 0),
                        (string) ($activity['contact_name'] ?? ''),
                    ) ?>
                    <td style="<?= $rowStyle ?>">
                        <strong><?= View::e((string) $activity['contact_name']) ?></strong>
                        &middot; <?= View::e($activityTypes[(string) $activity['activity_type']]
                            ?? (string) $activity['activity_type']) ?>
                        <span style="color:#8A857C;">
                            (<?= View::e((string) $activity['created_by_name']) ?>)
                        </span>
                        <?php if ((string) ($activity['summary'] ?? '') !== '') : ?>
                            <br><span style="color:#5A5650;">
                                <?= View::e((string) $activity['summary']) ?>
                            </span>
                        <?php endif ?>
                        <?php $attCount = (int) ($activity['attachment_count'] ?? 0); ?>
                        <?php if ($attCount > 0) : ?>
                            <br><span style="color:#8A857C;font-size:12px;">
                                &#128206; <?= $attCount ?> attachment<?= $attCount === 1 ? '' : 's' ?>
                            </span>
                        <?php endif ?>
                    </td></tr>
                <?php endforeach ?>
            </table>
        <?php endif ?>

        <p style="margin:28px 0 0;font-size:12px;color:#8A857C;">
            Sent every morning at 9 AM Eastern by
            <a href="<?= View::e($appUrl) ?>" style="color:#C1272D;">Rolo</a>.
        </p>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
