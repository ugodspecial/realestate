<?php
final class Mailer {
    public static function send(string $to,string $subject,string $body): bool {
        if(!config('mail_enabled') || !filter_var($to,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$subject)) return false;
        $from=config('mail_from') ?: setting('email');
        if(!filter_var($from,FILTER_VALIDATE_EMAIL)) return false;
        // Use the hosting provider's authenticated mail transport; never accept header input from a visitor.
        $headers=['From: '.brand().' <'.$from.'>','MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','X-Mailer: CedarTranquility'];
        try { return mail($to,'=?UTF-8?B?'.base64_encode($subject).'?=',wordwrap($body,900),implode("\r\n",$headers)); }
        catch(Throwable $e) { error_log('Mail delivery failed: '.$e->getMessage()); return false; }
    }
}
