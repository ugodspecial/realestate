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
    /* ---------------- Brand logo ---------------- */

    /**
     * Store the brand logo. Accepts SVG, PNG, JPEG, or WebP.
     * SVG is sanitized (scripts, event handlers, and external references are
     * stripped) because it is served from the same origin as the site.
     * Returns [relativePath|null, errorMessage|null].
     */
    public static function storeLogo(array $file): array {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) return [null, null];
        if ($error !== UPLOAD_ERR_OK) return [null, 'The logo upload failed. Please try again.'];
        if (!is_uploaded_file($file['tmp_name'] ?? '')) return [null, 'The logo upload failed. Please try again.'];
        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > 2 * 1024 * 1024) return [null, 'The logo must be smaller than 2 MB.'];

        $tmp = (string)$file['tmp_name'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
        $directory = ROOT_PATH . '/public/uploads/brand';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) return [null, 'The upload directory is not writable.'];

        if (in_array($mime, ['image/svg+xml', 'text/svg+xml', 'application/svg+xml'], true)) {
            $svg = self::sanitizeSvg((string)file_get_contents($tmp));
            if ($svg === null) return [null, 'The logo could not be read as an SVG image.'];
            $name = bin2hex(random_bytes(16)) . '.svg';
            if (file_put_contents($directory . '/' . $name, $svg) === false) return [null, 'The logo could not be saved.'];
            return ['uploads/brand/' . $name, null];
        }

        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !@getimagesize($tmp)) {
            return [null, 'The logo must be an SVG, PNG, JPG, or WebP image.'];
        }
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/png' => @imagecreatefrompng($tmp),
            'image/webp' => @imagecreatefromwebp($tmp),
        };
        if (!$image) return [null, 'The logo could not be read.'];
        $w = imagesx($image); $h = imagesy($image);
        if ($w < 1 || $h < 1 || $w * $h > 12000000) { imagedestroy($image); return [null, 'The logo image dimensions are too large.']; }
        $scale = min(1, 1200 / $w, 400 / $h);
        $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
        $clean = imagecreatetruecolor($nw, $nh);
        imagefill($clean, 0, 0, imagecolorallocate($clean, 255, 255, 255));
        imagecopyresampled($clean, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $name = bin2hex(random_bytes(16)) . '.webp';
        $ok = imagewebp($clean, $directory . '/' . $name, 90);
        imagedestroy($image); imagedestroy($clean);
        if (!$ok) return [null, 'The logo could not be saved.'];
        return ['uploads/brand/' . $name, null];
    }

    /** Remove the stored brand logo file, if any. */
    public static function deleteLogo(): void {
        $path = trim((string)setting('brand_logo'));
        if ($path === '' || !preg_match('#^uploads/brand/[a-zA-Z0-9._-]+$#', $path)) return;
        $file = ROOT_PATH . '/public/' . $path;
        if (is_file($file)) @unlink($file);
    }

    /**
     * Strip scripting and external references from an SVG document.
     * Returns null when the input is not a usable SVG.
     */
    private static function sanitizeSvg(string $svg): ?string {
        $svg = trim($svg);
        if ($svg === '' || strlen($svg) > 1024 * 1024) return null;
        if (!preg_match('/<svg\b/i', $svg)) return null;

        // Drop XML comments and CDATA wrappers that could hide markup.
        $svg = preg_replace('#<!--.*?-->#s', '', $svg) ?? $svg;
        $svg = preg_replace('#<!\[CDATA\[.*?\]\]>#s', '', $svg) ?? $svg;

        // Remove dangerous elements, including their contents.
        $svg = preg_replace('#<\s*(script|foreignObject|iframe|object|embed|audio|video|animate|animatetransform|animatemotion|set|handler|use|filter)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#<\s*(script|foreignObject|iframe|object|embed|audio|video|animate|animatetransform|animatemotion|set|handler)\b[^>]*/?>#is', '', $svg) ?? $svg;

        // Remove inline event handlers (onload, onclick, ...).
        $svg = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $svg) ?? $svg;

        // Remove javascript: and external references from href/xlink:href.
        // Decode HTML entities first so encoded schemes (&#106;avascript:) cannot slip through.
        $decoded = html_entity_decode($svg, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('#(?:href|xlink:href)\s*=\s*("|\')\s*(?:javascript|vbscript|data)\s*:#i', $decoded)) {
            $svg = preg_replace('#(?:href|xlink:href)\s*=\s*("|\')\s*(?:javascript|vbscript|data)\s*:[^"\']*("|\')#i', '', $svg) ?? $svg;
            $decoded = html_entity_decode($svg, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('#(?:href|xlink:href)\s*=\s*("|\')\s*(?:javascript|vbscript|data)\s*:#i', $decoded)) return null;
        }
        $svg = preg_replace('#(?:href|xlink:href)\s*=\s*("|\')\s*(?:https?:)?//[^"\']*("|\')#i', '', $svg) ?? $svg;

        // Remove style blocks and CSS imports.
        $svg = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#@import[^;]*;?#i', '', $svg) ?? $svg;

        // Remove DTDs, entity declarations, and processing instructions.
        $svg = preg_replace('#<!DOCTYPE[^>]*(?:\[.*?\])?[^>]*>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#<!ENTITY[^>]*>#is', '', $svg) ?? $svg;
        $svg = preg_replace('#<\?php.*?\?>#s', '', $svg) ?? $svg;
        $svg = preg_replace('#<\?=.*?\?>#s', '', $svg) ?? $svg;

        if ($svg === '' || !preg_match('/<svg\b/i', $svg)) return null;
        if (!preg_match('#</svg>#i', $svg)) $svg .= '</svg>';

        // Final guard: the sanitized document must not contain executable markup.
        $final = html_entity_decode($svg, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('#<\s*(script|iframe|object|embed|foreignObject|handler)\b#i', $final)) return null;
        if (preg_match('#\son[a-z]+\s*=#i', $final)) return null;
        if (preg_match('#(?:javascript|vbscript)\s*:#i', $final)) return null;

        return $svg . "\n";
    }
}