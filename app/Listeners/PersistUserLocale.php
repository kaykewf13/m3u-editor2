<?php

namespace App\Listeners;

use CraftForge\FilamentLanguageSwitcher\Events\LocaleChanged;

/**
 * Persists the user's locale selection to the database so it survives
 * cookie expiry, cache clears, and cross-device logins.
 *
 * Triggered by the craft-forge/filament-language-switcher LocaleChanged event
 * whenever a user selects a new language from the panel UI.
 */
class PersistUserLocale
{
    public function handle(LocaleChanged $event): void
    {
        if (! auth()->check()) {
            return;
        }

        auth()->user()->update(['locale' => $event->newLocale]);
    }
}
