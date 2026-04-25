<?php
declare(strict_types=1);

// Sanitises user-submitted text: trims whitespace, strips slashes, escapes HTML.
function clean_input(?string $data): string
{
    return htmlspecialchars(stripslashes(trim((string) $data)), ENT_QUOTES, 'UTF-8');
}
