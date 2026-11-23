<?php
final class Enquiries {
    /** The alert emails the website sends in one window, from every visitor together, are counted over this many seconds. */
    private const ALERT_WINDOW = 3600;

    public static function submit(array $source, ?array $property=null): array {
        $ip=$_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if(RateLimiter::tooMany('enquiry|'.$ip,10,3600)) return ['Please wait before sending another enquiry. You can also contact our team by phone.'];
        $data=['name'=>trim((string)($source['name'] ?? '')),'email'=>strtolower(trim((string)($source['email'] ?? ''))),'phone'=>trim((string)($source['phone'] ?? '')),'message'=>trim((string)($source['message'] ?? '')),'type'=>in_array($source['type'] ?? '',['enquiry','inspection','valuation','callback'],true)?$source['type']:'enquiry','preferred_date'=>trim((string)($source['preferred_date'] ?? ''))];
        $errors=[];
        if(mb_strlen($data['name'])<2 || mb_strlen($data['name'])>120 || preg_match('/[\r\n]/',$data['name'])) $errors[]='Please enter your full name (2–120 characters).';
        if(!Mailer::isAddress($data['email']) || mb_strlen($data['email'])>190) $errors[]='Please enter a valid email address.';
        if(!preg_match('/^[+0-9 ()\-]{7,25}$/',$data['phone'])) $errors[]='Please enter a valid phone number.';
        if(mb_strlen($data['message'])<10 || mb_strlen($data['message'])>5000) $errors[]='Please enter a message between 10 and 5,000 characters.';
        if(empty($source['consent'])) $errors[]='Please agree to be contacted about your enquiry.';
        if(!empty($source['website']) || time()-(int)($_SESSION['form_started'] ?? time())<3) $errors[]='Please take a moment to review the form and try again.';
        if($data['preferred_date']) {
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$data['preferred_date']);
            if(!$date || $date->format('Y-m-d')!==$data['preferred_date'] || $date->format('Y-m-d')<date('Y-m-d')) $errors[]='Choose today or a future inspection date.';
        }
        if($property && $property['publish_status']!=='published') $errors[]='This property is not currently published.';
        if($errors) return $errors;
        RateLimiter::hit('enquiry|'.$ip,3600);
        $reference='CTE-'.strtoupper(bin2hex(random_bytes(4)));
        $id=Database::insert('enquiries',array_merge($data,['reference'=>$reference,'property_id'=>$property['id'] ?? null,'status'=>'new','admin_notes'=>'','created_at'=>Database::now(),'updated_at'=>Database::now()]));
        // The enquiry is already saved, so nothing below can lose it or turn a visitor's thank-you
        // into an error. But if the alert does not go out, the office must be able to find out
        // (activity log; the reason is also written to the error log), not just never hear.
        if(Mailer::enabled()) {
            $office=Mailer::officeAddress();
            if($office==='') {
                Audit::log('enquiry.alert_failed','enquiry',(int)$id,'No alert email was sent: no valid notification or public email address is set in Website settings.');
            } else {
                $body="New enquiry $reference\n\nName: {$data['name']}\nEmail: {$data['email']}\nPhone: {$data['phone']}\nProperty: ".($property['title'] ?? 'General enquiry')."\n\n{$data['message']}\n\nManage enquiries in your ".brand()." dashboard.";
                self::alert($office,$reference,$body,$data['email'],(int)$id);
            }
        }
        unset($_SESSION['old'],$_SESSION['form_started']);
        return ['id'=>$id,'reference'=>$reference];
    }

    /**
     * Mail the office about an enquiry that is already saved.
     *
     * Two things stand between a visitor and the mail server. The visitor does not wait for it: where the server can
     * answer first (Mailer::afterResponse), the thank-you page is not held up by a mail server a hundred milliseconds
     * away, or one that does not answer. And the form cannot be turned into a flood: each address is held to ten
     * enquiries an hour (above), and all visitors together to MAIL_ALERT_LIMIT alerts an hour (60 unless set; 0 means no
     * limit). Many addresses sending enquiries is how a bot works, and it would otherwise fill the office's inbox, use up
     * the mail provider's allowance for the day (after which password-reset emails stop too) and get the sending account
     * marked as a spammer. Beyond the limit the enquiries are still saved and still on the dashboard; only the alert is
     * skipped, and that is written to the activity log once an hour, not once per enquiry.
     */
    private static function alert(string $office, string $reference, string $body, string $replyTo, int $id): void {
        $limit = (int) config('mail_alert_limit', 60);
        if ($limit > 0 && RateLimiter::tooMany('enquiry-alert|all', $limit, self::ALERT_WINDOW)) {
            if (!RateLimiter::tooMany('enquiry-alert-noted|all', 1, self::ALERT_WINDOW)) {
                RateLimiter::hit('enquiry-alert-noted|all', self::ALERT_WINDOW);
                Audit::log('enquiry.alert_suppressed','enquiry',$id,'No alert email was sent for this enquiry, or for others in this hour: '.$limit.' alerts have already gone out (MAIL_ALERT_LIMIT). The enquiries are saved: look in the dashboard.');
            }
            return;
        }
        RateLimiter::hit('enquiry-alert|all', self::ALERT_WINDOW);
        Mailer::afterResponse(static function () use ($office,$reference,$body,$replyTo,$id): void {
            // Reply goes to the person who wrote in.
            if(!Mailer::send($office,'New property enquiry · '.$reference,$body,$replyTo)) {
                Audit::log('enquiry.alert_failed','enquiry',$id,'The alert email could not be sent: '.Mailer::lastError());
            }
        });
    }
}
