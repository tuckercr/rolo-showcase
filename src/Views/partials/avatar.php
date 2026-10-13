<?php

/**
 * Contact avatar: the photo when one exists, an initials circle otherwise.
 * Include with:
 *   $avatar = ['contact' => $row, 'size' => 'w-8 h-8', 'text' => 'text-xs'];
 *   include <this file>;
 * The initials color is deterministic from the name, drawn from muted tones
 * that sit well with the brand palette.
 */

declare(strict_types=1);

use App\Support\AvatarTone;
use App\Support\View;

$avatarContact = $avatar['contact'];
$avatarSize = (string) ($avatar['size'] ?? 'w-8 h-8');
$avatarText = (string) ($avatar['text'] ?? 'text-xs');
$avatarName = trim((string) ($avatarContact['name'] ?? '')) ?: '?';
$avatarPhoto = $avatarContact['photo'] ?? null;

if ($avatarPhoto !== null && $avatarPhoto !== '') : ?>
    <img src="/contacts/<?= (int) $avatarContact['id'] ?>/photo?v=<?= View::e(substr((string) $avatarPhoto, 0, 8)) ?>"
         alt="" class="<?= $avatarSize ?> rounded-full object-cover shrink-0">
<?php else :
    $avatarInitials = AvatarTone::initials($avatarName);
    $avatarToneHex = AvatarTone::for($avatarName);
    $avatarTone = 'bg-[' . $avatarToneHex['bg'] . '] text-[' . $avatarToneHex['text'] . ']';
    ?>
    <span class="<?= $avatarSize ?> <?= $avatarTone ?> <?= $avatarText ?> rounded-full shrink-0
                 inline-flex items-center justify-center font-medium select-none">
        <?= View::e($avatarInitials) ?>
    </span>
<?php endif ?>
