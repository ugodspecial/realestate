<?php
$route=trim((string)($_GET['route'] ?? parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH)), '/');
$base=trim(base_path(),'/'); if($base && str_starts_with($route,$base.'/'))$route=substr($route,strlen($base)+1);
if($route==='public/index.php' || $route==='index.php')$route='';
if(str_starts_with($route,'public/'))$route=substr($route,7);
$_SESSION['form_started'] ??= time();
$method=request_method();
try {
    if($route==='' || $route==='home') { PublicController::home(); }
    elseif($route==='about')PublicController::about();
    elseif($route==='services')PublicController::services();
    elseif($route==='properties')PublicController::listings();
    elseif(preg_match('#^property/([a-z0-9-]+)$#',$route,$m))PublicController::property($m[1]);
    elseif($route==='contact')PublicController::contact();
    elseif($route==='enquiry' && $method==='POST')PublicController::enquiry();
    elseif($route==='login')PublicController::login();
    elseif($route==='logout' && $method==='POST')PublicController::logout();
    elseif($route==='account')PublicController::account();
    elseif($route==='privacy' || $route==='terms')PublicController::legal($route);
    elseif($route==='robots.txt')require ROOT_PATH.'/public/robots.php';
    elseif($route==='sitemap.xml')PublicController::sitemap();
    elseif($route==='health') { Database::value('SELECT 1');header('Content-Type: application/json');echo '{"status":"ok"}'; }
    elseif(str_starts_with($route,'admin')) { $user=Auth::require();AdminController::dispatch($route,$method,$user); }
    else abort_page(404,'We couldn’t find that page. Let’s get you back to a place worth exploring.');
} catch(PDOException $e) {
    error_log($e->getMessage());
    foreach(['no such table',"doesn't exist"] as $needle) if(str_contains($e->getMessage(),$needle))abort_page(503,'The website is being prepared. Please run the installation command in the deployment guide.');
    throw $e;
}
