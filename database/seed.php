<?php
return static function (): void {
    $now=Database::now();
    $settings=[
        'brand_name'=>'Cedar Tranquility Real Estate','brand_tagline'=>'REAL ESTATE','brand_logo'=>'','hero_eyebrow'=>'REAL ESTATE. REAL PEACE OF MIND.',
        'hero_title'=>"FIND YOUR SPACE.\nFEEL AT HOME.",
        'hero_subtitle'=>'Thoughtfully selected homes, land, and investment properties. Discover a more considered way to find your place in Nigeria.',
        'about_eyebrow'=>'ROOTED IN TRUST. BUILT AROUND YOU.',
        'about_title'=>"MORE THAN PROPERTY.\nA PARTNER FOR WHAT'S NEXT.",
        'about_summary'=>'A new home. A first investment. A space for your next big idea. Whatever brings you here, we believe the right property should open a door to a better future.',
        'about_body'=>"Cedar Tranquility Real Estate connects people with places they can believe in. From the energy of Lagos to the possibilities of Abuja, we bring local knowledge, careful selection, and a personal approach to every property journey.\n\nWe take time to understand what matters to you. Then we help you explore the options, ask the right questions, and move forward with clarity. No unnecessary complexity. No one-size-fits-all advice. Just thoughtful real estate, with people at its heart.",
        'contact_title'=>"EVERY GREAT MOVE\nSTARTS WITH A CONVERSATION.",
        'contact_intro'=>'Tell us what you have in mind. Our property advisors are here to help you take the next step.',
        'email'=>'hello@cedartranquility.example','notify_email'=>'hello@cedartranquility.example',
        'phone'=>'+234 800 000 0000','whatsapp'=>'2348000000000','address'=>'Victoria Island, Lagos, Nigeria',
        'office_hours'=>'Monday–Friday: 8:30am–5:30pm|Saturday: 10:00am–3:00pm',
        'footer_text'=>'Exceptional spaces. Thoughtful guidance. A more considered approach to real estate in Nigeria.',
        'privacy_contact'=>'Your enquiry is used only to respond to your request and arrange property services. Contact our team to request access, correction, or deletion of your information.',
    ];
    // Upsert so seeding is idempotent against migrations that pre-seed rows.
    $driver=Database::connection()->getAttribute(PDO::ATTR_DRIVER_NAME);
    foreach($settings as $key=>$value) {
        if($driver==='mysql') Database::query('INSERT INTO settings (key_name,value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$key,$value]);
        else Database::query('INSERT INTO settings (key_name,value) VALUES (?,?) ON CONFLICT(key_name) DO UPDATE SET value=excluded.value',[$key,$value]);
    }
    $credentials=[];
    foreach([['Amara Okonkwo','superadmin','super_admin'],['Tunde Adeyemi','admin','admin'],['Zainab Bello','manager','listing_manager'],['David Akpan','editor','content_editor']] as [$name,$alias,$role]) {
        $password=bin2hex(random_bytes(9)); $email=$alias.'@cedartranquility.example';
        Database::insert('users',['name'=>$name,'email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>$role,'active'=>1,'created_at'=>$now]);
        $credentials[$role]=['email'=>$email,'password'=>$password];
    }
    file_put_contents(ROOT_PATH.'/storage/demo-credentials.json',json_encode($credentials,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); chmod(ROOT_PATH.'/storage/demo-credentials.json',0600);
    $cats=[['Houses','houses','home','Space to settle in. Discover detached homes and villas with room for the life you imagine.'],['Apartments','apartments','building','Elevated city living, thoughtfully designed apartments, and a place to call your own.'],['Terraces','terraces','building','The best of community and privacy. Contemporary townhomes in connected neighborhoods.'],['Commercial','commercial','briefcase','Considered spaces for ambitious businesses, from boutique offices to established workplaces.'],['Land','land','land','A foundation for your future. Explore residential plots and long-term land opportunities.']];
    foreach($cats as $c) Database::insert('categories',['name'=>$c[0],'slug'=>$c[1],'icon'=>$c[2]==='briefcase'?'building':$c[2],'description'=>$c[3]]);
    $locations=[['Lekki','lekki','Lagos','Coastal energy, connected neighborhoods, and a new generation of exceptional homes.'],['Ikoyi','ikoyi','Lagos','Tree-lined streets and refined living in one of Lagos’s most established neighborhoods.'],['Victoria Island','victoria-island','Lagos','At the center of business, culture, and the city’s everyday possibilities.'],['Ikeja GRA','ikeja-gra','Lagos','Leafy residential streets, enduring character, and convenient mainland connections.'],['Banana Island','banana-island','Lagos','An exclusive island setting with distinctive architecture and a quieter pace.'],['Abuja','abuja','Federal Capital Territory','Purposeful spaces and fresh perspectives in the heart of the nation.']];
    foreach($locations as $l) Database::insert('locations',['name'=>$l[0],'slug'=>$l[1],'city'=>$l[2],'description'=>$l[3]]);
    $properties=[
        ['The Cedar Residence',' Contemporary five-bedroom villa',1,1,450000000,'sale','available',1,5,6,4,520,'hero-villa.jpg',2],
        ['Parkside Luxury Apartment','Three-bedroom home above the city',2,2,185000000,'sale','available',1,3,4,2,210,'apartments.jpg',4],
        ['The Adebola Terrace','A modern four-bedroom family townhouse',3,4,95000000,'sale','available',1,4,5,3,285,'terrace.jpg',6],
        ['Azure Waterfront Apartment','Three-bedroom apartment with lagoon views',2,1,12000000,'rent','available',0,3,4,2,195,'apartments.jpg',1],
        ['The Courtyard Villa','Five-bedroom detached home with private pool',1,5,650000000,'sale','available',0,5,6,5,680,'hero-villa.jpg',8],
        ['Victoria Business Suites','Premium serviced office floor',4,3,18000000,'rent','available',0,0,3,8,420,'office.jpg',3],
        ['Cedar Grove Estate Plots','Residential land in a growing corridor',5,1,35000000,'sale','available',0,0,0,0,600,'land.jpg',0],
        ['The Bourdillon Collection','Four-bedroom apartment in the heart of Ikoyi',2,2,320000000,'sale','available',0,4,5,3,310,'apartments.jpg',9],
        ['Palm Court Townhome','Four-bedroom terrace with a private garden',3,1,8500000,'rent','rented',0,4,4,2,260,'terrace.jpg',11],
        ['Maitama Garden Residence','Four-bedroom home with generous living spaces',1,6,280000000,'sale','available',0,4,5,3,410,'hero-villa.jpg',10],
        ['The Atlantic Workspace','Boutique office with a connected address',4,3,220000000,'sale','sold',0,0,4,6,380,'office.jpg',14],
        ['Ikeja Garden Terrace','A welcoming three-bedroom home in the GRA',3,4,72000000,'sale','available',0,3,4,2,220,'terrace.jpg',12],
        ['The Lagoon Penthouse','A private retreat above the city',2,1,22000000,'rent','available',0,4,5,3,340,'apartments.jpg',5],
    ];
    foreach($properties as $i=>$p) {
        [$title,$subtitle,$cat,$loc,$price,$deal,$status,$featured,$beds,$baths,$parking,$area,$image,$days]=$p;
        $description="$subtitle in {$locations[$loc-1][0]}, {$locations[$loc-1][2]}.\n\n".($cat===5?'Set within a thoughtfully planned neighborhood, this generous parcel offers a clear starting point for a home or a considered long-term investment. Convenient access, open surroundings, and room to create something meaningful make this an opportunity worth exploring.':($cat===4?'Designed for the way modern businesses work, this light-filled space combines a practical floor plan with a prestigious, well-connected address. Flexible work areas, welcoming arrival spaces, and excellent natural light create an environment where your team can do its best work.':'Welcome to a home where generous proportions meet everyday comfort. Natural light fills the principal living spaces, while carefully considered finishes bring warmth and character. Well-appointed bedrooms offer a quiet retreat, with thoughtfully arranged living and entertaining areas at the heart of the home.')) . "\n\nThe neighborhood balances convenient access to city essentials with the feeling of a place to unwind. Speak with our property team to discuss availability, arrange a private inspection, and request the full documentation pack.\n\nIllustrative demonstration listing. Photography is AI-generated; prices, specifications, and availability are sample data, not an offer for sale.";
        $amenities=$cat===5?['Planned road access','Residential development','Perimeter boundary','Green surroundings','Document review available','Inspection by appointment']:($cat===4?['Open-plan workspace','Meeting rooms','Fiber-ready infrastructure','24/7 access control','Dedicated parking','Backup power provision']:['Bright living spaces','Fitted kitchen','Backup power provision','Secure parking','En-suite bedrooms','Professional estate management']);
        $id=Database::insert('properties',['listing_id'=>'CT-'.(26001+$i),'slug'=>Properties::slug($title),'title'=>$title,'category_id'=>$cat,'location_id'=>$loc,'price'=>$price,'deal'=>$deal,'status'=>$status,'publish_status'=>$i===12?'pending':'published','featured'=>$featured,'beds'=>$beds,'baths'=>$baths,'parking'=>$parking,'area_sqm'=>$area,'address'=>$locations[$loc-1][0].', '.$locations[$loc-1][2].', Nigeria','description'=>$description,'amenities'=>json_encode($amenities),'latitude'=>null,'longitude'=>null,'created_by'=>1,'approved_by'=>$i===12?null:1,'created_at'=>gmdate('Y-m-d H:i:s',time()-$days*86400),'updated_at'=>$now,'seo_title'=>$title.' | '.$locations[$loc-1][0],'seo_description'=>excerpt($subtitle.' in '.$locations[$loc-1][0].'. Explore details and arrange a private viewing with Cedar Tranquility Real Estate.',160)]);
        $gallery=[[$image,$title.' — exterior'],[$image==='land.jpg'?'land.jpg':'living-room.jpg',$title.' — interior and surroundings'],[$image==='office.jpg'?'office.jpg':($image==='land.jpg'?'land.jpg':'hero-villa.jpg'),$title.' — architectural inspiration']];
        foreach(array_unique(array_column($gallery,0)) as $order=>$file) {
            $alt=array_values(array_filter($gallery,fn($g)=>$g[0]===$file))[0][1];
            Database::insert('property_images',['property_id'=>$id,'path'=>'assets/images/'.$file,'alt'=>$alt,'sort_order'=>$order,'is_cover'=>$order===0?1:0]);
        }
    }
    foreach([['A Nigerian family abroad','United Kingdom','We wanted someone who would listen before showing us options. The team understood what home meant to us and made the whole search feel personal and manageable.'],['First-time homebuyer','Lagos','Clear answers, patient guidance, and a shortlist that actually made sense for our budget. We felt supported at every step of the journey.'],['Property investor','Abuja','A thoughtful approach to finding the right opportunity. The conversations were practical, the details were clear, and the process stayed focused on our goals.']] as $t) Database::insert('testimonials',['name'=>$t[0],'location'=>$t[1],'quote'=>$t[2],'rating'=>5,'approved'=>1,'created_at'=>$now]);
    Database::insert('enquiries',['reference'=>'CTE-DEMO01','property_id'=>1,'name'=>'Sample client','email'=>'client@example.com','phone'=>'+2347000000000','message'=>'I would like to arrange a weekend inspection of The Cedar Residence. Please let me know the available times. This is a demonstration enquiry.','type'=>'inspection','preferred_date'=>gmdate('Y-m-d',strtotime('+3 days')),'status'=>'new','admin_notes'=>'','created_at'=>$now,'updated_at'=>$now]);
    Audit::log('system.installed','system',null,'Local demonstration installed with sample listings and staff accounts.');
};
