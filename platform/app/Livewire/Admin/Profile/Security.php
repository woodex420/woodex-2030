<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Profile;

use App\Domain\System\Models\TelegramLink;
use App\Integrations\Google\GoogleIdentity;
use App\Integrations\Google\GoogleOAuth;
use App\Integrations\Telegram\TelegramHealth;
use App\Integrations\Telegram\TelegramPairing;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Two-factor authentication (SYS-003) and Telegram linking (TGM-001) for the signed-in user.
 */
#[Layout('layouts::admin')]
#[Title('Security & Telegram')]
class Security extends Component
{
    public string $password = '';

    public string $code = '';

    public bool $showingSetup = false;

    public bool $showingRecoveryCodes = false;

    public function enableTwoFactor(): void
    {
        $this->confirmPassword();

        app(EnableTwoFactorAuthentication::class)($this->user());

        $this->password = '';
        $this->showingSetup = true;
    }

    public function confirmTwoFactor(): void
    {
        $this->validate(['code' => ['required', 'string', 'regex:/^[0-9]{6}$/']]);

        app(ConfirmTwoFactorAuthentication::class)($this->user(), $this->code);

        $this->code = '';
        $this->showingSetup = false;
        $this->showingRecoveryCodes = true;
        $this->dispatch('toast', message: 'Two-factor authentication is now on.');
    }

    public function regenerateRecoveryCodes(): void
    {
        $this->confirmPassword();

        app(GenerateNewRecoveryCodes::class)($this->user());

        $this->password = '';
        $this->showingRecoveryCodes = true;
    }

    public function disableTwoFactor(): void
    {
        $this->confirmPassword();

        $user = $this->user();

        if ($user->requiresTwoFactorAuthentication()) {
            throw ValidationException::withMessages(['password' => 'Your role requires two-factor authentication. It cannot be turned off.']);
        }

        app(DisableTwoFactorAuthentication::class)($user);

        $this->password = '';
        $this->showingSetup = false;
        $this->showingRecoveryCodes = false;
        $this->dispatch('toast', message: 'Two-factor authentication turned off.', type: 'warning');
    }

    public function createTelegramCode(): void
    {
        abort_unless($this->user()->canUseTelegram(), 403);
        if (! $this->telegramConfigured()) {
            $missing = match (true) {
                blank(config('services.telegram.bot_token')) => 'TELEGRAM_BOT_TOKEN is not set on the server',
                blank(config('services.telegram.webhook_secret')) => 'TELEGRAM_WEBHOOK_SECRET is not set on the server',
                app(TelegramPairing::class)->botUsername() === null => 'TELEGRAM_BOT_USERNAME is empty — a pairing link would open no bot',
                default => 'Telegram is not configured',
            };
            $this->dispatch('toast', message: "Telegram cannot pair yet: {$missing}. The connection health card below names every step.", type: 'warning');

            return;
        }

        TelegramLink::issueCodeFor($this->user());
        $this->dispatch('toast', message: 'Code created. Scan the QR or open the link within 30 minutes.');
    }

    public function unlinkTelegram(): void
    {
        abort_unless($this->user()->canUseTelegram(), 403);
        $this->user()->telegramLink()->update(['chat_id' => null, 'is_active' => false, 'linked_at' => null, 'link_code' => null, 'link_code_expires_at' => null]);
        $this->dispatch('toast', message: 'Telegram unlinked.', type: 'warning');
    }

    public function render(): View
    {
        $user = $this->user();
        $user->load('telegramLink');

        // Slice L: the pairing link and its server-rendered QR exist only while a live code does;
        // the health ladder is computed in every state so a dead end always names its failing step.
        $pairing = app(TelegramPairing::class);
        $health = app(TelegramHealth::class);
        $telegramAllowed = $user->canUseTelegram();
        $telegram = $telegramAllowed ? $user->telegramLink : null;
        $telegramStartUrl = $telegram?->link_code && $telegram->link_code_expires_at?->isFuture()
            ? $pairing->startUrl($telegram->link_code)
            : null;

        return view('livewire.admin.profile.security', [
            'user' => $user,
            'googleIdentity' => GoogleIdentity::query()->where('user_id', $user->id)->first(['email']),
            'googleConfigured' => GoogleOAuth::configured(),
            'googleLoginEnabled' => GoogleOAuth::loginEnabled(),
            'enabled' => $user->hasTwoFactorEnabled(),
            'requiresTwoFactor' => $user->requiresTwoFactorAuthentication(),
            'pending' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null,
            'telegramAllowed' => $telegramAllowed,
            'telegram' => $telegram,
            'telegramConfigured' => $telegramAllowed && $this->telegramConfigured(),
            'telegramStartUrl' => $telegramStartUrl,
            'telegramQrSvg' => $telegramStartUrl !== null ? $pairing->qrSvg($telegramStartUrl) : null,
            'telegramHealth' => $telegramAllowed ? $health->steps($telegram) : [],
            'telegramLastMessageAt' => $telegramAllowed ? $health->lastMessageAt($telegram) : null,
            'setupKey' => ! $user->hasTwoFactorEnabled() && $user->two_factor_secret !== null
                ? decrypt($user->two_factor_secret) : null,
        ]);
    }

    private function telegramConfigured(): bool
    {
        return filled(config('services.telegram.bot_token'))
            && filled(config('services.telegram.webhook_secret'))
            && app(TelegramPairing::class)->botUsername() !== null;
    }

    private function confirmPassword(): void
    {
        $this->validate(['password' => ['required', 'string']]);

        if (! Hash::check($this->password, $this->user()->password)) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
