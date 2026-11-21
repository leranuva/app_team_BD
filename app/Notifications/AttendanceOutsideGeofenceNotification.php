<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AttendanceOutsideGeofenceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly array $attempt)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->attempt;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Intento de fichaje fuera del radio')
            ->line($this->attempt['worker_name'].' intentó fichar '.$this->attempt['operation'].' fuera del radio permitido.')
            ->line('Obra: '.$this->attempt['job_site_name'])
            ->line('Hora del intento: '.$this->attempt['attempted_at']);
    }
}
