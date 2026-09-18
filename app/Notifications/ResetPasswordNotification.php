<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $frontend = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');
        $url = $frontend.'/reset-password?token='.urlencode($this->token).'&email='.urlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject('Reset Password Ledger')
            ->greeting('Halo '.$notifiable->name.',')
            ->line('Kami menerima permintaan untuk mereset password akun Ledger Anda.')
            ->action('Reset Password', $url)
            ->line('Tautan ini akan kedaluwarsa dalam '.config('auth.passwords.users.expire', 60).' menit.')
            ->line('Jika Anda tidak meminta reset password, abaikan email ini.');
    }
}
