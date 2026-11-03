<?php
if(PHP_SAPI!=='cli') exit('CLI only.');
require dirname(__DIR__).'/app/bootstrap.php';
$options=getopt('',['demo','name:','email:','password:','password-file:']);
$demo=isset($options['demo']);
if($demo && config('app_env')!=='local') exit('Demo installation is allowed only with APP_ENV=local.'.PHP_EOL);
$pdo=Database::connection();
[$tables,$indexes]=(require ROOT_PATH.'/database/schema.php')($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
foreach($tables as $sql) $pdo->exec($sql);
foreach($indexes as $sql) { try{$pdo->exec($sql);}catch(PDOException $e){if(!str_contains(strtolower($e->getMessage()),'duplicate') && !str_contains(strtolower($e->getMessage()),'already exists'))throw $e;} }
if((int)Database::value('SELECT COUNT(*) FROM users')>0) exit('Schema is ready. Existing content was preserved.'.PHP_EOL);
if($demo) { (require ROOT_PATH.'/database/seed.php')(); echo "Demo installed. Account credentials: storage/demo-credentials.json\n"; }
else {
    $email=strtolower(trim($options['email'] ?? '')); $name=trim($options['name'] ?? 'Super Administrator');
    $password=isset($options['password-file']) ? trim(file_get_contents($options['password-file'])) : ($options['password'] ?? getenv('CEDAR_ADMIN_PASSWORD') ?: '');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<14) exit("Provide --email and a password of at least 14 characters via CEDAR_ADMIN_PASSWORD or --password-file.\n");
    Database::insert('users',['name'=>$name,'email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>'super_admin','active'=>1,'created_at'=>Database::now()]);
    $defaults=['brand_name'=>'Cedar Tranquility Real Estate','hero_title'=>"FIND YOUR SPACE.\nFEEL AT HOME.",'hero_subtitle'=>'Discover a more considered approach to property.','email'=>$email,'notify_email'=>$email,'phone'=>'','whatsapp'=>'','address'=>'','office_hours'=>'','about_title'=>"MORE THAN PROPERTY.\nA PARTNER FOR WHAT'S NEXT.",'about_summary'=>'Thoughtful real estate, with people at its heart.','about_body'=>'','contact_title'=>'LET’S FIND YOUR NEXT CHAPTER.','contact_intro'=>'Talk to our property team.','footer_text'=>'Thoughtful real estate, with people at its heart.'];
    foreach($defaults as $key=>$value)Database::insert('settings',['key_name'=>$key,'value'=>$value]);
    foreach([['Houses','houses'],['Apartments','apartments'],['Terraces','terraces'],['Commercial','commercial'],['Land','land']] as $c)Database::insert('categories',['name'=>$c[0],'slug'=>$c[1],'description'=>'','icon'=>$c[1]==='land'?'land':'home']);
    echo "Production schema and Super Administrator created. Sign in and configure your website.\n";
}
