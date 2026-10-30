<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitation extends Notification
{
    public function __construct(private string $organizationName, private string $acceptUrl) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Smart Market AI — tashkilot taklifnomasi')
            ->greeting('Assalomu alaykum!')
            ->line($this->organizationName.' tashkiloti sizni ish maydoniga taklif qildi.')
            ->line('Havola 7 kun amal qiladi. Taklif yuborilgan email bilan kiring va uni tasdiqlang.')
            ->action('Taklifnomani ko‘rish', $this->acceptUrl)
            ->line('Taklifni kutmagan bo‘lsangiz, ushbu xabarni e’tiborsiz qoldiring.');
    }
}
