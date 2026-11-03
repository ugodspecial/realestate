<?php
final class PublicController {
    public static function home(): void {
        $categories=Database::all('SELECT c.*, (SELECT COUNT(*) FROM properties p WHERE p.category_id=c.id AND p.publish_status=\'published\') AS total FROM categories c ORDER BY c.id');
        $locations=Database::all('SELECT l.*, (SELECT COUNT(*) FROM properties p WHERE p.location_id=l.id AND p.publish_status=\'published\' AND p.status=\'available\') AS total FROM locations l ORDER BY l.id');
        render('public/home',['title'=>brand().' | Exceptional spaces. Thoughtful guidance.','description'=>setting('hero_subtitle'),'featured'=>Properties::featured(),'latest'=>Properties::latest(6),'categories'=>$categories,'locations'=>$locations,'testimonials'=>Database::all('SELECT * FROM testimonials WHERE approved=1 ORDER BY id DESC LIMIT 3'),'filters'=>[]]);
    }
    public static function about(): void { render('public/about',['title'=>'About Us | '.brand(),'description'=>setting('about_summary'),'page'=>'about']); }
    public static function services(): void { render('public/services',['title'=>'Our Services | '.brand(),'description'=>'Property sales, residential lettings, land investment, and property advisory. Thoughtful real estate services from Cedar Tranquility Real Estate.','page'=>'services']); }
    public static function listings(): void {
        $filters=Properties::filters(); $page=max(1,min(10000,(int)query_value('page','1')));
        $result=Properties::search($filters,$page,9);
        $title='Properties for sale and rent in Nigeria';
        if(!empty($filters['category'])) { $cat=Database::one('SELECT name FROM categories WHERE slug=?',[$filters['category']]); if($cat)$title=$cat['name'].' for sale and rent'; }
        if(!empty($filters['location'])) { $loc=Database::one('SELECT name FROM locations WHERE slug=?',[$filters['location']]); if($loc)$title='Properties in '.$loc['name']; }
        render('public/listings',['title'=>$title.' | '.brand(),'description'=>'Explore thoughtfully selected homes, apartments, commercial spaces, and land. Search by location, budget, and property type with Cedar Tranquility Real Estate.','page'=>'properties','filters'=>$filters,'result'=>$result,'categories'=>Database::all('SELECT * FROM categories ORDER BY name'),'locations'=>Database::all('SELECT * FROM locations ORDER BY name')]);
    }
    public static function property(string $slug): void {
        $p=Properties::find($slug); if(!$p)abort_page(404,'This property may have moved or is no longer available. Explore our current listings to find your next place.');
        $images=Properties::images((int)$p['id']);
        $schema=['@context'=>'https://schema.org','@type'=>'RealEstateListing','name'=>$p['title'],'description'=>excerpt(strip_tags($p['description']),300),'url'=>rtrim(config('app_url') ?: ((is_https()?'https://':'http://').($_SERVER['HTTP_HOST'] ?? 'localhost')),'/').url('property/'.$p['slug']),'datePosted'=>substr($p['created_at'],0,10),'mainEntity'=>['@type'=>$p['category_name']==='Land'?'LandForm':'Residence','name'=>$p['title'],'address'=>['@type'=>'PostalAddress','addressLocality'=>$p['location_name'],'addressRegion'=>$p['city'],'addressCountry'=>'NG']]];
        if($images)$schema['image']=array_map(fn($i)=>(is_https()?'https://':'http://').($_SERVER['HTTP_HOST'] ?? 'localhost').image_url($i['path']),array_slice($images,0,5));
        render('public/property',['title'=>($p['seo_title'] ?: $p['title']).' | '.brand(),'description'=>$p['seo_description'] ?: excerpt($p['description'],160),'p'=>$p,'images'=>$images,'related'=>Properties::related($p),'schema'=>$schema,'page'=>'properties']);
    }
    public static function contact(): void { render('public/contact',['title'=>'Contact Us | '.brand(),'description'=>'Start a conversation with Cedar Tranquility Real Estate. Arrange a property inspection, request a valuation, or speak to a property advisor.','page'=>'contact']); }
    public static function enquiry(): void {
        verify_csrf(); $p=null;
        if(input('property_id')) { $p=Properties::byId((int)input('property_id')); if(!$p || $p['publish_status']!=='published')abort_page(404,'This property is not available for enquiries.'); }
        $result=Enquiries::submit($_POST,$p);
        if(isset($result['reference'])) { flash('success','Thank you, '.explode(' ',input('name'))[0].'. Your enquiry has been received. Your reference is '.$result['reference'].'. Our team will contact you using the details you provided.'); redirect($p?'property/'.$p['slug'].'#enquiry':'contact#enquiry'); }
        remember_old($_POST); flash('error',implode(' ',$result)); redirect($p?'property/'.$p['slug'].'#enquiry':'contact#enquiry');
    }
    public static function login(): void {
        if(Auth::user())redirect('admin');
        if(request_method()==='POST') {
            verify_csrf();
            if(Auth::attempt(strtolower(input('email')), (string)input('password'),$_SERVER['REMOTE_ADDR'] ?? '')) { clear_old(); flash('success','Welcome back. You’re securely signed in.'); redirect('admin'); }
            remember_old($_POST); flash('error','We couldn’t sign you in. Check your details and try again. After several attempts, please wait 15 minutes.'); redirect('login');
        }
        echo view('auth/login',['demo_hint'=>config('demo_mode') ? 'Demo sign-in details are in storage/demo-credentials.json on this machine.' : '']);
    }
    public static function logout(): void { verify_csrf(); Auth::logout(); flash('success','You have been signed out.'); redirect('login'); }
    public static function account(): void { Auth::require(); redirect('admin/account'); }
    public static function sitemap(): void {
        header('Content-Type: application/xml; charset=UTF-8');
        if(config('demo_mode')) { echo '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>';return; }
        $base=rtrim(config('app_url'),'/');
        $paths=['','about','services','properties','contact','privacy'];
        foreach(Database::all("SELECT slug,updated_at FROM properties WHERE publish_status='published'") as $p)$paths[]='property/'.$p['slug'];
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach($paths as $path)echo '<url><loc>'.e($base.url($path)).'</loc></url>';
        echo '</urlset>';
    }
    public static function legal(string $type): void { render('public/legal',['title'=>($type==='privacy'?'Privacy Policy':'Website Terms').' | '.brand(),'description'=>'Information about using Cedar Tranquility Real Estate’s website and services.','type'=>$type,'page'=>'']); }
}
