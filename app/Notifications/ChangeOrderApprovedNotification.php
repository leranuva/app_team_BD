<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ChangeOrderApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $orderId, private readonly string $code, private readonly string $title)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        return ['change_order_id' => $this->orderId, 'code' => $this->code, 'title' => $this->title];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Change order approved '.$this->code)
            ->line($this->title)
            ->line('The change order was approved and its impact was applied.')
            ->action('View operations', route('dashboard'));
    }
}
