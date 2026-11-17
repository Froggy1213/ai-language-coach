<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\EmailVerificationToken;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailNotification extends Notification
{
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

        $url = EmailVerificationToken::makeUrl($notifiable);

        return (new MailMessage)
            ->subject('【Kirie】メールアドレスの確認')
            ->greeting("{$notifiable->name}さん、こんにちは。")
            ->line('Kirieをご利用いただきありがとうございます。以下のボタンからメールアドレスの確認を完了してください。')
            ->action('メールアドレスを確認する', $url)
            ->line('このリンクの有効期限は60分です。心当たりがない場合は、このメールを破棄してください。');
    }
}
