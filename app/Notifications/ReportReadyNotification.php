<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReportReadyNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly string $url, public readonly string $label) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reporte listo')
            ->line($this->label.' ya está generado. El enlace caduca en 15 minutos.')
            ->action('Descargar', $this->url);
    }

    /**
     * @return array{url: string, label: string}
     */
    public function toArray(object $notifiable): array
    {
        return ['url' => $this->url, 'label' => $this->label];
    }
}
