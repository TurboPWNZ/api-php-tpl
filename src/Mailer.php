<?php

namespace Api;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    private array $config;
    private ?PHPMailer $mailer = null;

    public function __construct(array $mailConfig = [])
    {
        $this->config = $mailConfig;
    }

    private function getMailer(): PHPMailer
    {
        if ($this->mailer === null) {
            $this->mailer = new PHPMailer(true);
            
            $driver = $this->config['driver'] ?? 'sendmail';
            
            $this->mailer->setFrom(
                $this->config['from']['email'] ?? 'noreply@example.com',
                $this->config['from']['name'] ?? 'Old MMO'
            );
            
            if ($driver === 'smtp') {
                $smtp = $this->config['smtp'] ?? [];
                $this->mailer->isSMTP();
                $this->mailer->Host = $smtp['host'] ?? 'smtp.gmail.com';
                $this->mailer->Port = $smtp['port'] ?? 587;
                $this->mailer->SMTPSecure = $smtp['encryption'] ?? 'tls';
                $this->mailer->SMTPAuth = !empty($smtp['username']) && !empty($smtp['password']);
                if ($this->mailer->SMTPAuth) {
                    $this->mailer->Username = $smtp['username'];
                    $this->mailer->Password = $smtp['password'];
                }
            } elseif ($driver === 'mail') {
                $this->mailer->isMail();
            } else {
                $this->mailer->isSendmail();
            }
            
            $this->mailer->CharSet = 'UTF-8';
        }
        
        return $this->mailer;
    }

    public function send(string $to, string $subject, string $message, string $contentType = 'text/plain'): bool
    {
        try {
            $mailer = $this->getMailer();
            $mailer->ClearAddresses();
            $mailer->AddAddress($to);
            $mailer->Subject = $subject;
            $mailer->Body = $contentType === 'text/html' ? $message : nl2br($message);
            $mailer->ContentType = $contentType;
            
            if ($contentType === 'text/plain') {
                $mailer->AltBody = $message;
            }
            
            return $mailer->send();
        } catch (Exception $e) {
            return false;
        }
    }

    public function sendToUser(int $userId, string $subject, string $message): bool
    {
        $account = new \Api\db\Account();
        $user = $account->findByPk($userId);
        
        if (!$user) {
            throw new \RuntimeException('User not found');
        }
        
        return $this->send($user['email'], $subject, $message);
    }
}
