<?php
final class Uploads {
    public static function store(array $files,int $propertyId,string $title): array {
        $names=$files['name'] ?? []; $saved=[]; $errors=[];
        if(!is_array($names)) return ['Invalid upload.'];
        if(count($names)>8) return ['Upload no more than 8 images at a time.'];
        $existing=(int)Database::value('SELECT COUNT(*) FROM property_images WHERE property_id=?',[$propertyId]);
        if($existing+count($names)>20) return ['A property can have up to 20 images.'];
        $directory=ROOT_PATH.'/public/uploads/'.date('Y/m');
        if(!is_dir($directory) && !mkdir($directory,0755,true)) return ['The upload directory is not writable.'];
        $finfo=new finfo(FILEINFO_MIME_TYPE);
        foreach($names as $i=>$name) {
            $error=$files['error'][$i];
            if($error===UPLOAD_ERR_NO_FILE) continue;
            if($error!==UPLOAD_ERR_OK || $files['size'][$i]>config('upload_max_bytes')) { $errors[]="$name: upload failed or exceeds 5 MB."; continue; }
            $tmp=$files['tmp_name'][$i];
            $mime=$finfo->file($tmp);
            if(!in_array($mime,['image/jpeg','image/png','image/webp'],true) || !@getimagesize($tmp)) { $errors[]="$name: only JPG, PNG, or WebP images are accepted."; continue; }
            $image=match($mime) {'image/jpeg'=>@imagecreatefromjpeg($tmp),'image/png'=>@imagecreatefrompng($tmp),'image/webp'=>@imagecreatefromwebp($tmp)};
            if(!$image) { $errors[]="$name: this image could not be read."; continue; }
            $w=imagesx($image); $h=imagesy($image);
            if($w*$h>30000000) { imagedestroy($image); $errors[]="$name: image dimensions are too large."; continue; }
            $scale=min(1,1920/$w,1440/$h); $nw=max(1,(int)round($w*$scale)); $nh=max(1,(int)round($h*$scale));
            $clean=imagecreatetruecolor($nw,$nh); imagefill($clean,0,0,imagecolorallocate($clean,255,255,255));
            imagecopyresampled($clean,$image,0,0,0,0,$nw,$nh,$w,$h);
            $path='uploads/'.date('Y/m').'/'.bin2hex(random_bytes(16)).'.webp';
            $ok=imagewebp($clean,ROOT_PATH.'/public/'.$path,85);
            imagedestroy($image); imagedestroy($clean);
            if(!$ok) { $errors[]="$name: could not save the image."; continue; }
            $id=Database::insert('property_images',['property_id'=>$propertyId,'path'=>$path,'alt'=>mb_substr($title.' — property photograph '.($existing+$i+1),0,255),'sort_order'=>$existing+$i,'is_cover'=>$existing===0 && $i===0 ? 1 : 0]);
            $saved[]=$id;
        }
        if($saved && !(int)Database::value('SELECT COUNT(*) FROM property_images WHERE property_id=? AND is_cover=1',[$propertyId])) Database::update('property_images',['is_cover'=>1],$saved[0]);
        return $errors;
    }
    public static function delete(array $image): void {
        $path=ROOT_PATH.'/public/'.$image['path'];
        if(str_starts_with($image['path'],'uploads/') && is_file($path)) unlink($path);
        Database::query('DELETE FROM property_images WHERE id=?',[$image['id']]);
        if($image['is_cover']) {
            $next=Database::one('SELECT id FROM property_images WHERE property_id=? ORDER BY sort_order,id LIMIT 1',[$image['property_id']]);
            if($next) Database::update('property_images',['is_cover'=>1],(int)$next['id']);
        }
    }
}
