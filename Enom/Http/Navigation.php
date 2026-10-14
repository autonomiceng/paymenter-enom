<?php

namespace Paymenter\Extensions\Servers\Enom\Http;

/** Navigation items the extension adds to Paymenter's theme. Registered from Enom::boot(). */
final class Navigation
{
    /**
     * "Domains" in the top navigation for everyone, linking to the public search.
     * Full page loads (spa false): the client pages are plain Blade, not Livewire components.
     */
    public static function domains(): array
    {
        return ['name' => 'Domains', 'route' => 'enom.domains.search', 'icon' => 'ri-global', 'spa' => false, 'priority' => 15];
    }
}
