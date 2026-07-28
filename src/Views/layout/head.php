<?php

declare(strict_types=1);

use App\Support\View;

/**
 * Shared <head> contents — Data Story Strategy brand palette + fonts,
 * sampled from the client's public site:
 *   red #C1272D (CTAs), creams #F3EFE7 / #EDE7DC (section backgrounds),
 *   ink #111 / warm muted #5A5650, Cormorant Garamond headings, Inter body.
 * Deliberately no near-black page backgrounds (Colin's call) — the creams
 * are the "dark" layer here.
 *
 * @var string $headTitle
 */
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= View::e($headTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?php $fontsUrl = 'https://fonts.googleapis.com/css2'
    . '?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400'
    . '&family=Inter:wght@400;500;600;700&display=swap'; ?>
<link href="<?= $fontsUrl ?>" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    // Back/forward navigation can restore a stale memory snapshot (bfcache) —
    // e.g. snooze a contact, hit Back, dashboard still shows it overdue.
    // Reload ONLY in that case; normal loads are untouched.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            window.location.reload();
        }
    });
</script>
<script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    brand: {
                        red: '#C1272D',
                        reddark: '#9E2025',
                        cream: '#F3EFE7',
                        cream2: '#EDE7DC',
                        sand: '#DCD3C2',
                        ink: '#111111',
                        inksoft: '#3A3630',
                        muted: '#5A5650',
                    },
                },
                fontFamily: {
                    serif: ['"Cormorant Garamond"', 'Georgia', 'serif'],
                    sans: ['Inter', 'sans-serif'],
                },
            },
        },
    };
</script>
