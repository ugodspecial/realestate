<?php
final class Properties {
    public const COLUMNS = 'p.*, c.name AS category_name, c.slug AS category_slug, l.name AS location_name, l.slug AS location_slug, l.city, (SELECT path FROM property_images i WHERE i.property_id=p.id ORDER BY i.is_cover DESC,i.sort_order ASC,i.id ASC LIMIT 1) AS image';
    public const SOURCE = 'properties p JOIN categories c ON c.id=p.category_id JOIN locations l ON l.id=p.location_id';
    public const SELECT = 'SELECT ' . self::COLUMNS . ' FROM ' . self::SOURCE;
    public static function find(string $slug, bool $public = true): ?array {
        return Database::one(self::SELECT . ' WHERE p.slug = ?' . ($public ? " AND p.publish_status='published'" : ''), [$slug]);
    }
    public static function byId(int $id): ?array { return Database::one(self::SELECT . ' WHERE p.id=?',[$id]); }
    public static function featured(int $limit=3): array { return Database::all(self::SELECT . " WHERE p.publish_status='published' AND p.featured=1 AND p.status='available' ORDER BY p.created_at DESC LIMIT $limit"); }
    public static function latest(int $limit=6): array { return Database::all(self::SELECT . " WHERE p.publish_status='published' ORDER BY p.created_at DESC LIMIT $limit"); }
    public static function related(array $p): array { return Database::all(self::SELECT . " WHERE p.publish_status='published' AND p.status='available' AND p.id<>? AND (p.category_id=? OR p.location_id=?) ORDER BY p.featured DESC,p.created_at DESC LIMIT 3",[$p['id'],$p['category_id'],$p['location_id']]); }
    public static function images(int $id): array { return Database::all('SELECT * FROM property_images WHERE property_id=? ORDER BY is_cover DESC,sort_order,id',[$id]); }
    public static function filters(): array {
        $allowed=['q','deal','category','location','min_price','max_price','beds','baths','status','sort','view','featured'];
        $f=[]; foreach($allowed as $key) { $v=query_value($key); if($v!=='') $f[$key]=mb_substr($v,0,180); }
        return $f;
    }
    public static function search(array $f, int $page=1, int $perPage=9, bool $admin=false): array {
        $where=[]; $params=[];
        if(!$admin) $where[]="p.publish_status='published'";
        if(!empty($f['q'])) {
            $where[]='(p.title LIKE ? OR p.description LIKE ? OR p.listing_id LIKE ? OR l.name LIKE ? OR l.city LIKE ? OR c.name LIKE ?)';
            $term='%' . str_replace(['%','_'], ['\%','\_'], $f['q']) . '%';
            array_push($params,...array_fill(0,6,$term));
        }
        foreach(['deal'=>'p.deal','status'=>'p.status'] as $key=>$column) if(!empty($f[$key])) { $where[]="$column = ?"; $params[]=$f[$key]; }
        foreach(['category'=>'c.slug','location'=>'l.slug'] as $key=>$column) if(!empty($f[$key])) { $where[]="$column = ?"; $params[]=$f[$key]; }
        foreach(['min_price'=>'>=','max_price'=>'<='] as $key=>$operator) if(isset($f[$key]) && $f[$key]!=='' && is_numeric($f[$key])) { $where[]="p.price $operator ?"; $params[]=max(0,(float)$f[$key]); }
        foreach(['beds','baths'] as $key) if(!empty($f[$key]) && ctype_digit((string)$f[$key])) { $where[]="p.$key >= ?"; $params[]=(int)$f[$key]; }
        if(!empty($f['featured'])) $where[]='p.featured=1';
        if(!empty($f['publish_status'])) { $where[]='p.publish_status=?'; $params[]=$f['publish_status']; }
        $sql=' FROM properties p JOIN categories c ON c.id=p.category_id JOIN locations l ON l.id=p.location_id' . ($where ? ' WHERE '.implode(' AND ',$where) : '');
        $count=(int)Database::value('SELECT COUNT(*)'.$sql,$params);
        $sorts=['newest'=>'p.created_at DESC,p.id DESC','price_asc'=>'p.price ASC,p.id DESC','price_desc'=>'p.price DESC,p.id DESC','oldest'=>'p.created_at ASC,p.id ASC'];
        $order=$sorts[$f['sort'] ?? 'newest'] ?? $sorts['newest'];
        $page=max(1,$page); $perPage=max(1,min(48,$perPage)); $offset=($page-1)*$perPage;
        $rows=Database::all('SELECT '.self::COLUMNS.$sql." ORDER BY $order LIMIT $perPage OFFSET $offset",$params);
        return ['items'=>$rows,'total'=>$count,'page'=>$page,'pages'=>max(1,(int)ceil($count/$perPage))];
    }
    public static function validate(array $data): array {
        $errors=[];
        if(mb_strlen($data['title'])<5 || mb_strlen($data['title'])>180) $errors[]='Enter a property title between 5 and 180 characters.';
        if(!is_numeric($data['price']) || (float)$data['price']<=0 || (float)$data['price']>9999999999999) $errors[]='Enter a valid price greater than zero.';
        if(!Database::one('SELECT id FROM categories WHERE id=?',[$data['category_id']])) $errors[]='Choose a valid property category.';
        if(!Database::one('SELECT id FROM locations WHERE id=?',[$data['location_id']])) $errors[]='Choose a valid location.';
        if(!in_array($data['deal'],['sale','rent'],true) || !in_array($data['status'],['available','sold','rented'],true)) $errors[]='Choose a valid listing classification and availability.';
        foreach(['beds','baths','parking','area_sqm'] as $key) if(!is_numeric($data[$key]) || (float)$data[$key]<0 || (float)$data[$key]>10000000) $errors[]='Property specifications must be valid, non-negative numbers.';
        if(mb_strlen($data['description'])<30) $errors[]='Add a property description of at least 30 characters.';
        if(empty($data['address'])) $errors[]='Enter a property address or neighborhood.';
        return $errors;
    }
    public static function slug(string $title, ?int $except=null): string {
        $base=trim(preg_replace('/[^a-z0-9]+/','-',strtolower($title)),'-') ?: 'property'; $slug=$base; $i=2;
        while(Database::one('SELECT id FROM properties WHERE slug=?'.($except?' AND id<>?':''),$except?[$slug,$except]:[$slug])) $slug=$base.'-'.$i++;
        return $slug;
    }
}
