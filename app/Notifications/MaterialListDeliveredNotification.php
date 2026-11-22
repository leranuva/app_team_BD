<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MaterialListDeliveredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $listId, private readonly string $code, private readonly string $title)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['material_list_id' => $this->listId, 'code' => $this->code, 'title' => $this->title];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Material list delivered '.$this->code)
            ->line($this->title)
            ->line('Delivery was recorded. Record usage separately.')
            ->action('View operations', route('dashboard'));
    }
}
