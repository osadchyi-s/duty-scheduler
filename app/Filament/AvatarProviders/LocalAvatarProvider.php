<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Generates default avatars locally as inline SVG data URIs instead of
 * fetching them from ui-avatars.com, so the app has no external dependency
 * and works offline without request timeouts.
 */
class LocalAvatarProvider implements AvatarProvider
{
    public function get(Model | Authenticatable $record): string
    {
        $name = trim((string) Filament::getNameForDefaultAvatar($record));

        $initials = collect(preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY))
            ->take(2)
            ->map(fn (string $segment): string => mb_strtoupper(mb_substr($segment, 0, 1)))
            ->implode('');

        $initials = $initials !== '' ? $initials : '?';

        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">
                <rect width="100" height="100" fill="#09090b"/>
                <text x="50" y="50" dy=".35em" fill="#FFFFFF" font-size="40"
                    font-family="system-ui, -apple-system, Segoe UI, Roboto, sans-serif"
                    text-anchor="middle">{$initials}</text>
            </svg>
            SVG;

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
