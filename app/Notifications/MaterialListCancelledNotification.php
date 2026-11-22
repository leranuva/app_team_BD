<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MaterialListCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $listId,
        private readonly string $code,
        private readonly string $title,
        private readonly string $reason,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['material_list_id' => $this->listId, 'code' => $this->code, 'title' => $this->title, 'reason' => $this->reason];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Lista de materiales cancelada '.$this->code)
            ->line($this->title)
            ->line($this->reason)
            ->action('Ver operaciones', route('dashboard'));
    }
}
