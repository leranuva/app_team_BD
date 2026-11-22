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
            ->subject('Time clock attempt outside the geofence')
            ->line($this->attempt['worker_name'].' attempted to clock '.($this->attempt['operation'] === 'entrada' ? 'in' : 'out').' outside the allowed radius.')
            ->line('Job site: '.$this->attempt['job_site_name'])
            ->line('Attempt time: '.$this->attempt['attempted_at']);
    }
}
