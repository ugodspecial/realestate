<?php
final class Audit {
    public static function log(string $action,string $type,?int $id,string $details): void {
        Database::insert('audit_logs',['user_id'=>$_SESSION['user_id'] ?? null,'action'=>$action,'entity_type'=>$type,'entity_id'=>$id,'details'=>mb_substr($details,0,1000),'ip'=>mb_substr($_SERVER['REMOTE_ADDR'] ?? 'cli',0,45),'created_at'=>Database::now()]);
    }
}
