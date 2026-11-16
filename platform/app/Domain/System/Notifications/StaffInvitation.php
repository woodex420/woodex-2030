<?php

declare(strict_types=1);

namespace App\Domain\System\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation email with a set-your-password link (uses the password broker, so the link expires).
 */
final class StaffInvitation extends Notification
{
    public function __construct(public readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        $minutes = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Your Woodex OS account')
            ->greeting("Assalam o Alaikum {$notifiable->name},")
            ->line('You now have access to Woodex OS, the Woodex Interior sales and projects system.')
            ->action('Set your password', $url)
            ->line("This link works for {$minutes} minutes. If it has expired, ask for a new invitation or use “Forgot password” on the sign-in page.")
            ->line('After signing in you will be asked to turn on two-factor authentication with an authenticator app.');
    }
}
