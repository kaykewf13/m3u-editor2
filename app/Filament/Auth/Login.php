<?php

namespace App\Filament\Auth;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class Login extends \Filament\Auth\Pages\Login
{
    public function mount(): void
    {
        // Auto-redirect to OIDC provider if configured
        if (
            config('services.oidc.enabled')
            && config('services.oidc.auto_redirect')
            && ! request()->has('local')
            && ! session()->has('oidc_error')
        ) {
            $this->redirect(route('auth.oidc.redirect'));

            return;
        }

        parent::mount();
    }

    /**
     * Get the form fields for the component.
     */
    public function form(Schema $schema): Schema
    {
        // Hide the login form entirely when OIDC is the sole auth method
        if (config('services.oidc.enabled') && config('services.oidc.hide_login_form')) {
            return $schema
                ->components([])
                ->statePath('data');
        }

        return $schema
            ->components([
                // $this->getEmailFormComponent(),
                $this->getLoginFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getRememberFormComponent(),
            ])
            ->statePath('data');
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        if (config('services.oidc.enabled') && config('services.oidc.hide_login_form')) {
            return [];
        }

        return parent::getFormActions();
    }

    /**
     * Get the login form component.
     */
    protected function getLoginFormComponent(): Component
    {
        return TextInput::make('login')
            ->label('Login')
            ->required()
            ->autocomplete()
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    /**
     * Login using either username or email address.
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        $login_type = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'name';

        return [
            $login_type => $data['login'],
            'password' => $data['password'],
        ];
    }

    /**
     * Failure message.
     */
    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.login' => 'Invalid login or password. Please try again.',
        ]);
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (session()->has('oidc_error')) {
            $error = e(session('oidc_error'));

            return new HtmlString(
                "<span class=\"text-danger-600 dark:text-danger-400\">{$error}</span>"
            );
        }

        return parent::getSubheading();
    }
}
