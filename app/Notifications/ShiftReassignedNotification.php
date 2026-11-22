<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ShiftReassignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $shiftId,
        private readonly string $site,
        private readonly string $previousStart,
        private readonly string $previousEnd,
        private readonly string $start,
        private readonly string $end,
        private readonly string $actorName,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'shift_id' => $this->shiftId,
            'job_site_name' => $this->site,
            'previous_start' => $this->previousStart,
            'previous_end' => $this->previousEnd,
            'start' => $this->start,
            'end' => $this->end,
            'actor_name' => $this->actorName,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Shift schedule changed')
            ->line('Job site: '.$this->site)
            ->line('Previous schedule: '.$this->previousStart.' to '.$this->previousEnd)
            ->line('New schedule: '.$this->start.' to '.$this->end)
            ->line('Rescheduled by: '.$this->actorName)
            ->action('View operations', route('dashboard'));
    }
}
