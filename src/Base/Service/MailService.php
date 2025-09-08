<?php

namespace Base\Service;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailService
{
   public function __construct(
      private string $fromAddress,
      private string $fromName,
      private ?array $smtpSettings = null
   )
   {
   }

   public function send(string $to, string $subject, string $bodyHtml, string $bodyText = ''): bool
   {
      $mail = new PHPMailer(true);

      if ($this->smtpSettings !== null)
      {
         $mail->isSMTP();
         $mail->Host = $this->smtpSettings['host'] ?? 'localhost';
         $mail->Port = $this->smtpSettings['port'] ?? 1025;

         if ($this->smtpSettings['username']??null)
         {
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpSettings['username'];
            $mail->Password = $this->smtpSettings['password'] ?? null;
         }
         else
         {
            $mail->SMTPAuth = false;
         }
      }

      try
      {
         // Sender
         $mail->setFrom($this->fromAddress, $this->fromName);

         // Recipient
         $mail->addAddress($to);

         // Content
         $mail->CharSet = 'UTF-8';
         $mail->isHTML(true);
         $mail->Subject = $subject;
         $mail->Body    = $bodyHtml;
         $mail->AltBody = $bodyText ?: strip_tags($bodyHtml);

         $mail->send();
         return true;
      }
      catch (Exception $e)
      {
         // hier ggf. Logging einbauen
         error_log("Mailer Error: " . $mail->ErrorInfo);
         return false;
      }
   }
}
