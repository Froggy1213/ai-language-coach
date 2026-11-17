<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    public function __construct(
        #[\SensitiveParameter] public readonly string $token,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        assert($notifiable instanceof User);

        $url = rtrim((string) config('app.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('【Kirie】パスワードの再設定')
            ->greeting("{$notifiable->name}さん、こんにちは。")
            ->line('パスワード再設定のリクエストを受け付けました。以下のボタンから新しいパスワードを設定してください。')
            ->action('パスワードを再設定する', $url)
            ->line('このリンクの有効期限は60分です。リクエストに心当たりがない場合は、このまま何もせずこのメールを破棄してください。');
    }
}
