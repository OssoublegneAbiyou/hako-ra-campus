<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Courriel de rappel facultatif. Il ne contient qu'un titre neutre et un lien vers une page protégée :
 * ni note, ni intitulé de cours ou de tâche, ni texte personnel.
 */
class CampusReminder extends Notification
{
    public function __construct(public string $title, public string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title.' — HAKO-RA Campus')
            ->line($this->title.'.')
            ->action('Ouvrir dans Campus', $this->url)
            ->line('Le détail s’affiche après connexion. Vous pouvez désactiver ces courriels dans vos préférences de notification.');
    }
}
