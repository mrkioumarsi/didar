<?php
$C=['db_host'=>'localhost','db_name'=>'didar','db_user'=>'root','db_pass'=>'','turn'=>[]];
if(file_exists(__DIR__.'/config.local.php'))include __DIR__.'/config.local.php';
error_reporting(E_ALL&~E_NOTICE&~E_WARNING&~E_DEPRECATED);
ini_set('display_errors','0');
$https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||(($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');
define('LOGIN_DAYS',90);
$sd=__DIR__.'/storage/sessions';
if(!is_dir($sd)){@mkdir($sd,0700,true);@file_put_contents($sd.'/index.html','');}
if(is_dir($sd)&&is_writable($sd))ini_set('session.save_path',$sd);
ini_set('session.gc_maxlifetime',(string)(LOGIN_DAYS*86400));
ini_set('session.gc_probability','1');
ini_set('session.gc_divisor','200');
ini_set('session.use_strict_mode','1');
if(PHP_VERSION_ID>=70300)session_set_cookie_params(['lifetime'=>LOGIN_DAYS*86400,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
else session_set_cookie_params(LOGIN_DAYS*86400,'/','',$https,true);
session_start();
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(16));
if(PHP_VERSION_ID>=70300)setcookie(session_name(),session_id(),['expires'=>time()+LOGIN_DAYS*86400,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
if(PHP_SAPI!=='cli'&&!headers_sent()){
 header('X-Frame-Options: SAMEORIGIN');
 header('X-Content-Type-Options: nosniff');
 header('Referrer-Policy: same-origin');
 header('X-Robots-Tag: noindex');
}

function db(){
 static $p,$tzset,$fail;
 global $C;
 if($fail)throw new RuntimeException('db');
 if(!$p){
  try{
   $opt=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
   if(defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT'))$opt[PDO::MYSQL_ATTR_CONNECT_TIMEOUT]=3;
   $p=new PDO("mysql:host={$C['db_host']};dbname={$C['db_name']};charset=utf8mb4",$C['db_user'],$C['db_pass'],$opt);
  }catch(Throwable $e){$fail=1;throw $e;}
 }
 if(!$tzset){
  $tzset=1;
  try{
   $z=$p->query("SELECT v FROM settings WHERE k='timezone'")->fetchColumn();
   if(!$z||!in_array($z,timezone_identifiers_list(),true))$z='Asia/Tehran';
   date_default_timezone_set($z);
   $off=(new DateTime('now',new DateTimeZone($z)))->format('P');
   $p->exec('SET time_zone='.$p->quote($off));
  }catch(Throwable $e){date_default_timezone_set('Asia/Tehran');}
 }
 return $p;
}
function q($s,$a=[]){$t=db()->prepare($s);$t->execute($a);return $t;}
function setting($k){try{return q("SELECT v FROM settings WHERE k=?",[$k])->fetchColumn()?:'';}catch(Exception $e){return '';}}
function is_private($ip){return $ip==='::1'||!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE);}
if(!$https&&!is_private($_SERVER['SERVER_ADDR']??'')&&!is_private($_SERVER['REMOTE_ADDR']??'')&&PHP_SAPI!=='cli'){header('Location: https://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);exit;}
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function base(){global $https;$d=trim(setting('domain'));if($d)return rtrim(preg_match('~^https?://~',$d)?$d:'https://'.$d,'/');return ($https?'https':'http').'://'.$_SERVER['HTTP_HOST'].rtrim(dirname($_SERVER['SCRIPT_NAME']),'/');}
function asset($f){return $f.'?v='.@filemtime(__DIR__.'/'.$f);}
function ice_servers(){
 global $C;
 $st=array_values(array_filter(array_map('trim',explode(',',setting('stun')))));
 if(!$st)$st=['stun:stun.l.google.com:19302'];
 $ice=[['urls'=>$st]];
 if($u=trim(setting('turn_url'))){
  if($sec=trim(setting('turn_secret'))){$un=(time()+86400).':didar';$cr=base64_encode(hash_hmac('sha1',$un,$sec,true));}
  else{$un=setting('turn_user');$cr=setting('turn_pass');}
  $ice[]=['urls'=>array_values(array_filter(array_map('trim',explode(',',$u)))),'username'=>$un,'credential'=>$cr];
 }elseif(!empty($C['turn']))$ice[]=$C['turn'];
 return $ice;
}
function ensure_schema(){
 static $d;
 if($d)return;
 $d=1;
 $f=__DIR__.'/storage/schema.ok';
 if(@file_get_contents($f)==='4'){apply_seed();return;}
 foreach(["participants ADD can_cam TINYINT DEFAULT 0","participants ADD can_board TINYINT DEFAULT 0","participants ADD cam_on TINYINT DEFAULT 0","room_members ADD can_cam TINYINT DEFAULT 0","room_members ADD can_board TINYINT DEFAULT 0","rooms ADD description TEXT NULL","rooms ADD rec_enabled TINYINT DEFAULT 1","chat ADD owner VARCHAR(20) DEFAULT ''","chat ADD deleted_at DATETIME NULL","users ADD bio VARCHAR(300) NOT NULL DEFAULT ''","users ADD avatar VARCHAR(100) NULL","users ADD lang VARCHAR(8) NOT NULL DEFAULT ''","settings MODIFY k VARCHAR(40) NOT NULL"] as $s){try{db()->exec("ALTER TABLE $s");}catch(Exception $e){}}
 db()->exec("CREATE TABLE IF NOT EXISTS auth_tokens(id INT AUTO_INCREMENT PRIMARY KEY,selector VARCHAR(24) UNIQUE,hash CHAR(64),user_id INT,expires DATETIME,KEY(user_id)) DEFAULT CHARSET=utf8mb4");
 foreach(['timezone'=>'Asia/Tehran','link_mode'=>'p2p','pass_policy'=>'low','default_lang'=>'fa','quality'=>'mid'] as $k=>$v)q("INSERT IGNORE INTO settings(k,v) VALUES(?,?)",[$k,$v]);
 apply_seed();
 @file_put_contents($f,'4');
}
function apply_seed(){
 global $C;
 if(empty($C['seed'])||!is_array($C['seed']))return;
 if(@file_get_contents(__DIR__.'/storage/seed.ok')==='1')return;
 foreach($C['seed'] as $k=>$v){
  if(!is_string($k)||!is_scalar($v))continue;
  if(!preg_match('/^[a-z0-9_]{1,40}$/',$k))continue;
  q("REPLACE INTO settings VALUES(?,?)",[$k,(string)$v]);
 }
 @file_put_contents(__DIR__.'/storage/seed.ok','1');
}
function auth_cookie($v,$exp){global $https;if(PHP_VERSION_ID>=70300)setcookie('dauth',$v,['expires'=>$exp,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);else setcookie('dauth',$v,$exp,'/','',$https,true);}
function auth_login($uid){
 if(mt_rand(1,20)==1)q("DELETE FROM auth_tokens WHERE expires<NOW()");
 $sel=bin2hex(random_bytes(9));$val=bin2hex(random_bytes(24));$exp=time()+LOGIN_DAYS*86400;
 q("INSERT INTO auth_tokens(selector,hash,user_id,expires) VALUES(?,?,?,?)",[$sel,hash('sha256',$val),$uid,date('Y-m-d H:i:s',$exp)]);
 auth_cookie($sel.':'.$val,$exp);
}
function auth_restore(){
 if(!empty($_SESSION['uid'])||empty($_COOKIE['dauth']))return;
 $pr=explode(':',$_COOKIE['dauth']);
 if(count($pr)!==2)return;
 $r=q("SELECT * FROM auth_tokens WHERE selector=? AND expires>NOW()",[$pr[0]])->fetch();
 if($r&&hash_equals($r['hash'],hash('sha256',$pr[1]))&&q("SELECT 1 FROM users WHERE id=?",[$r['user_id']])->fetch()){
  $_SESSION['uid']=(int)$r['user_id'];
  $exp=time()+LOGIN_DAYS*86400;
  q("UPDATE auth_tokens SET expires=? WHERE id=?",[date('Y-m-d H:i:s',$exp),$r['id']]);
  auth_cookie($_COOKIE['dauth'],$exp);
 }else auth_cookie('',time()-3600);
}
function auth_logout(){if(!empty($_COOKIE['dauth'])){$pr=explode(':',$_COOKIE['dauth']);q("DELETE FROM auth_tokens WHERE selector=?",[$pr[0]]);}auth_cookie('',time()-3600);}
function out($d=[]){header('Content-Type: application/json; charset=utf-8');echo json_encode($d,JSON_UNESCAPED_UNICODE);exit;}
function fail($m){http_response_code(400);out(['error'=>$m]);}
function user(){
 if(empty($_SESSION['uid']))return null;
 ensure_schema();
 return q("SELECT id,username,name,role,avatar,bio,lang FROM users WHERE id=?",[$_SESSION['uid']])->fetch()?:null;
}
function can_manage($rid){$u=user();if(!$u)return false;if($u['role']==='admin')return true;return (bool)q("SELECT 1 FROM room_members WHERE room_id=? AND user_id=? AND is_manager=1",[$rid,$u['id']])->fetch();}
function room_access($rid){
 if(can_manage($rid))return true;
 if($u=user()){if(q("SELECT 1 FROM room_members WHERE room_id=? AND user_id=?",[$rid,$u['id']])->fetch())return true;}
 foreach(array_keys($_SESSION['p']??[]) as $pid){if(q("SELECT 1 FROM participants WHERE id=? AND room_id=?",[$pid,$rid])->fetch())return true;}
 return false;
}
function pass_policy(){$p=setting('pass_policy');return in_array($p,['low','mid','high'],true)?$p:'low';}
function pass_min(){$p=pass_policy();return $p==='high'?8:($p==='mid'?6:4);}
function pass_fail($pw){
 $pw=(string)$pw;
 if(mb_strlen($pw)<pass_min())fail(t('err.pass_min',['n'=>locn(pass_min())]));
 if(pass_policy()==='high'){
  if(!preg_match('/\p{L}/u',$pw))fail(t('err.pass_letter'));
  if(!preg_match('/\d/',$pw))fail(t('err.pass_digit'));
 }
}
function login_file(){return __DIR__.'/storage/logins.json';}
function login_bucket(){
 $all=json_decode((string)@file_get_contents(login_file()),true);
 if(!is_array($all))$all=[];
 $now=time();
 foreach($all as $k=>$v)if(($v['t']??0)<$now-900)unset($all[$k]);
 return $all;
}
function login_blocked(){
 $all=login_bucket();
 $ip=hash('sha256',$_SERVER['REMOTE_ADDR']??'x');
 $row=$all[$ip]??null;
 return $row&&($row['n']??0)>=12&&($row['t']??0)>time()-900;
}
function login_mark($ok){
 $all=login_bucket();
 $ip=hash('sha256',$_SERVER['REMOTE_ADDR']??'x');
 if($ok)unset($all[$ip]);
 else{
  $row=$all[$ip]??['n'=>0,'t'=>time()];
  if(($row['t']??0)<time()-900)$row=['n'=>0,'t'=>time()];
  $row['n']=(int)$row['n']+1;$row['t']=time();$all[$ip]=$row;
 }
 @file_put_contents(login_file(),json_encode($all),LOCK_EX);
}
function b64url($s){return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
function lk_token($identity,$name,$room){
 $key=trim(setting('lk_key'));$sec=trim(setting('lk_secret'));
 if($key===''||$sec==='')return '';
 $now=time();
 $head=b64url(json_encode(['alg'=>'HS256','typ'=>'JWT']));
 $body=b64url(json_encode(['iss'=>$key,'sub'=>(string)$identity,'nbf'=>$now,'exp'=>$now+21600,'name'=>(string)$name,'video'=>['room'=>(string)$room,'roomJoin'=>true,'canPublish'=>true,'canSubscribe'=>true,'canPublishData'=>true]]));
 return $head.'.'.$body.'.'.b64url(hash_hmac('sha256',$head.'.'.$body,$sec,true));
}
function link_mode(){return setting('link_mode')==='sfu'?'sfu':'p2p';}
function csrf_ok(){
 $sent=$_POST['csrf']??($_SERVER['HTTP_X_CSRF']??'');
 return is_string($sent)&&$sent!==''&&!empty($_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],$sent);
}
function log_err($e){
 $dir=__DIR__.'/storage';
 if(!is_dir($dir))return;
 @file_put_contents($dir.'/error.log',date('c').' '.$e->getMessage()."\n",FILE_APPEND|LOCK_EX);
}

require __DIR__.'/lib/jdate.php';
require __DIR__.'/lib/i18n.php';
require __DIR__.'/lib/monitor.php';
if(PHP_SAPI!=='cli'){
 if(file_exists(__DIR__.'/storage/installed.lock')){try{ensure_schema();auth_restore();}catch(Throwable $e){log_err($e);}}
 try{i18n_boot();}catch(Throwable $e){$LANG='fa';$T=[];$TF=[];$LANG_META=['dir'=>'rtl','digits'=>'fa'];}
}
