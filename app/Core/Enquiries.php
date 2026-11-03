<?php
final class Enquiries {
    public static function submit(array $source, ?array $property=null): array {
        $ip=$_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if(RateLimiter::tooMany('enquiry|'.$ip,10,3600)) return ['Please wait before sending another enquiry. You can also contact our team by phone.'];
        $data=['name'=>trim((string)($source['name'] ?? '')),'email'=>strtolower(trim((string)($source['email'] ?? ''))),'phone'=>trim((string)($source['phone'] ?? '')),'message'=>trim((string)($source['message'] ?? '')),'type'=>in_array($source['type'] ?? '',['enquiry','inspection','valuation','callback'],true)?$source['type']:'enquiry','preferred_date'=>trim((string)($source['preferred_date'] ?? ''))];
        $errors=[];
        if(mb_strlen($data['name'])<2 || mb_strlen($data['name'])>120 || preg_match('/[\r\n]/',$data['name'])) $errors[]='Please enter your full name (2–120 characters).';
        if(!filter_var($data['email'],FILTER_VALIDATE_EMAIL) || mb_strlen($data['email'])>190) $errors[]='Please enter a valid email address.';
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
        if(config('mail_enabled') && filter_var(setting('notify_email',setting('email')),FILTER_VALIDATE_EMAIL)) {
            $body="New enquiry $reference\n\nName: {$data['name']}\nEmail: {$data['email']}\nPhone: {$data['phone']}\nProperty: ".($property['title'] ?? 'General enquiry')."\n\n{$data['message']}\n\nManage enquiries in your Cedar Tranquility dashboard.";
            Mailer::send(setting('notify_email',setting('email')),'New property enquiry · '.$reference,$body);
        }
        unset($_SESSION['old'],$_SESSION['form_started']);
        return ['id'=>$id,'reference'=>$reference];
    }
}
