<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification
{
    public function __construct(public string $url, public string $perimeter, public string $role, public CarbonInterface $expiresAt) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Invitation HAKO-RA Campus')
            ->line('Vous êtes invité à rejoindre « '.$this->perimeter.' » avec le rôle : '.$this->role.'.')
            ->line('Connectez-vous avec votre compte existant si vous en avez un : votre compte reste unique.')
            ->action('Ouvrir l’invitation', $this->url)
            ->line('Ce lien est personnel et expire le '.$this->expiresAt->timezone(config('app.display_timezone'))->format('d/m/Y à H:i').'. Ne le transférez pas.');
    }
}
