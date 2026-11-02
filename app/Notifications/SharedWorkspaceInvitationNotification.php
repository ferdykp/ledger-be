<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SharedWorkspaceInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $workspaceName,
        private readonly string $inviteUrl,
        private readonly string $inviterName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Undangan ruang keuangan bersama di Ledger')
            ->greeting('Halo,')
            ->line($this->inviterName.' mengundang Anda bergabung ke ruang “'.$this->workspaceName.'”.')
            ->line('Undangan hanya berlaku untuk alamat email penerima dan akan kedaluwarsa dalam 7 hari.')
            ->action('Lihat undangan', $this->inviteUrl)
            ->line('Masuk atau buat akun Ledger dengan alamat email yang menerima undangan untuk bergabung.');
    }
}
