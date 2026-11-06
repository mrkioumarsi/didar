<?php
require __DIR__.'/../config.php';
$root=dirname(__DIR__);
if(isset($_GET['lang'])){
 $c=lang_code($_GET['lang']);
 if($c){lang_cookie($c);header('Location: install.php');exit;}
}
if(!lang_code($_COOKIE['dlang']??'')){
 $LANG='en';
 $LANG_META=lang_list()['en']??['name'=>'English','dir'=>'ltr','digits'=>''];
 $T=lang_strings('en');
}
$locked=is_file($root.'/storage/installed.lock');
$done=isset($_GET['done']);
$langs=lang_list();
$cur=html_lang();
$dir=html_dir();
$err='';
$reqs=[
 ['req.php',PHP_VERSION_ID>=80000],
 ['req.pdo',extension_loaded('pdo_mysql')],
 ['req.gd',extension_loaded('gd')],
 ['req.mb',extension_loaded('mbstring')],
 ['req.openssl',extension_loaded('openssl')],
 ['req.json',extension_loaded('json')],
 ['req.session',extension_loaded('session')],
];
$stDir=$root.'/storage';
$avDir=$root.'/assets/avatars';
if(!is_dir($stDir))@mkdir($stDir,0755,true);
if(!is_dir($avDir))@mkdir($avDir,0755,true);
$reqs[]=['req.storage',is_dir($stDir)&&is_writable($stDir)];
$reqs[]=['req.avatars',is_dir($avDir)&&is_writable($avDir)];
$ready=true;
foreach($reqs as $r)if(!$r[1])$ready=false;
$in=function($k,$d=''){return $_POST[$k]??$d;};
if(!$locked&&($_SERVER['REQUEST_METHOD']??'')==='POST'){
 if(!csrf_ok())$err=t('err.csrf');
 elseif(!$ready)$err=t('install.blocked');
 else{
  $host=trim((string)$in('db_host','localhost'));
  $name=trim((string)$in('db_name','didar'));
  $user=trim((string)$in('db_user'));
  $pass=(string)$in('db_pass','');
  $au=trim((string)$in('admin_user'));
  $an=trim((string)$in('admin_name'));
  $ap=(string)$in('admin_pass','');
  $brand=trim((string)$in('brand','دیدار'));
  $lang=lang_code($in('lang'))?:'en';
  $tz=trim((string)$in('tz','Asia/Tehran'));
  $cal=($in('cal')==='gregorian'||$in('cal')==='jalali')?$in('cal'):(html_lang()==='en'?'gregorian':'jalali');
  if(!in_array($tz,timezone_identifiers_list(),true))$tz='Asia/Tehran';
  if($brand==='')$brand='دیدار';
  if(!preg_match('/^[A-Za-z0-9._:-]+$/',$host)||!preg_match('/^[A-Za-z0-9_]+$/',$name)||!preg_match('/^[A-Za-z0-9_@.-]+$/',$user))$err=t('install.dbfail');
  elseif(mb_strlen($au)<3||mb_strlen($au)>50)$err=t('err.username');
  elseif(mb_strlen($ap)<4)$err=t('err.pass_min',['n'=>locn(4)]);
  else{
   try{
    $opt=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
    if(defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT'))$opt[PDO::MYSQL_ATTR_CONNECT_TIMEOUT]=5;
    $pdo=new PDO('mysql:host='.$host.';dbname='.$name.';charset=utf8mb4',$user,$pass,$opt);
    $raw=file_get_contents(__DIR__.'/schema.sql');
    foreach(array_filter(array_map('trim',explode(';',$raw))) as $sql){if($sql!=='')$pdo->exec($sql);}
    $ins=$pdo->prepare('INSERT IGNORE INTO settings(k,v) VALUES(?,?)');
    foreach(['calendar'=>$cal,'timezone'=>'Asia/Tehran','link_mode'=>'p2p','pass_policy'=>'low','default_lang'=>'en','quality'=>'mid','brand'=>$brand,'domain'=>''] as $k=>$v)$ins->execute([$k,$v]);
    if(!empty($C['seed'])&&is_array($C['seed'])){
     $rep=$pdo->prepare('REPLACE INTO settings(k,v) VALUES(?,?)');
     foreach($C['seed'] as $k=>$v){
      if(!is_string($k)||!is_scalar($v))continue;
      if(!preg_match('/^[a-z0-9_]{1,40}$/',$k))continue;
      $rep->execute([$k,(string)$v]);
     }
    }
    $rep=$pdo->prepare('REPLACE INTO settings(k,v) VALUES(?,?)');
    foreach(['brand'=>$brand,'calendar'=>$cal,'timezone'=>$tz,'default_lang'=>$lang] as $k=>$v)$rep->execute([$k,$v]);
    $n=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if($n===0){
     if($an==='')$an=$au;
     $pdo->prepare("INSERT INTO users(username,pass,name,role,lang) VALUES(?,?,?,'admin',?)")->execute([$au,password_hash($ap,PASSWORD_DEFAULT),mb_substr($an,0,100),$lang]);
    }
    $cfg=['db_host'=>$host,'db_name'=>$name,'db_user'=>$user,'db_pass'=>$pass,'turn'=>is_array($C['turn']??null)?$C['turn']:[]];
    if(!empty($C['seed'])&&is_array($C['seed']))$cfg['seed']=$C['seed'];
    file_put_contents($root.'/config.local.php',"<?php\n\$C=".var_export($cfg,true).";\n");
    file_put_contents($root.'/storage/schema.ok','4');
    file_put_contents($root.'/storage/seed.ok','1');
    file_put_contents($root.'/storage/installed.lock','1');
    @unlink(__DIR__.'/schema.sql');
    @unlink(__FILE__);
    header('Location: ../index.php');
    exit;
   }catch(Throwable $e){log_err($e);$err=t('install.dbfail');}
  }
 }
}
$fv=function($k,$d='')use($in){return h($in($k,$d));};
?><!doctype html><html lang="<?=h($cur)?>" dir="<?=h($dir)?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h(t('install.title'))?></title><link rel="stylesheet" href="../assets/app.css?v=<?=(int)@filemtime($root.'/assets/app.css')?>"><link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;700&display=swap" rel="stylesheet"></head><body>
<div class="loginwrap"><div class="card login" style="max-width:460px;text-align:start"><img src="../assets/icon-192.png" alt="" style="display:block;margin:0 auto"><h2 style="text-align:center"><?=t('install.title')?></h2>
<div class="langs"><?php foreach($langs as $id=>$m):?><a class="<?=$id===$cur?'act':''?>" href="install.php?lang=<?=h($id)?>"><?=h($m['name']??$id)?></a><?php endforeach;?></div>
<?php if($locked):?>
<p><?=$done?t('install.done'):t('install.locked')?></p>
<p><?=t('install.remove')?></p>
<p><a class="btn" href="../index.php"><?=t('install.open')?></a></p>
<?php else:?>
<p class="mu"><?=t('install.lead')?></p>
<p class="hint"><?=t('install.shared')?></p>
<?php if($err):?><p class="err"><?=h($err)?></p><?php endif;?>
<h3><?=t('install.req')?></h3>
<div class="reqs"><?php foreach($reqs as $r):?><div class="req <?=$r[1]?'ok':'bad'?>"><span><?=t($r[0])?></span><b><?=$r[1]?t('install.req.ok'):t('install.req.bad')?></b></div><?php endforeach;?></div>
<form method="post">
<input type="hidden" name="csrf" value="<?=h($_SESSION['csrf']??'')?>">
<h3><?=t('install.db')?></h3>
<p class="hint"><?=t('install.db.note')?></p>
<label><?=t('install.db.host')?></label><input name="db_host" dir="ltr" value="<?=$fv('db_host',$C['db_host']??'localhost')?>" required>
<label><?=t('install.db.name')?></label><input name="db_name" dir="ltr" value="<?=$fv('db_name',$C['db_name']??'didar')?>" required>
<label><?=t('install.db.user')?></label><input name="db_user" dir="ltr" value="<?=$fv('db_user',$C['db_user']??'')?>" required>
<label><?=t('install.db.pass')?></label><input name="db_pass" dir="ltr" value="<?=$fv('db_pass',$C['db_pass']??'')?>">
<h3><?=t('install.admin')?></h3>
<label><?=t('install.admin.user')?></label><input name="admin_user" dir="ltr" value="<?=$fv('admin_user')?>" required>
<label><?=t('install.admin.name')?></label><input name="admin_name" value="<?=$fv('admin_name')?>">
<label><?=t('install.admin.pass')?></label><input name="admin_pass" type="password" dir="ltr" required>
<label><?=t('install.brand')?></label><input name="brand" value="<?=$fv('brand',setting('brand')?:'دیدار')?>">
<label><?=t('install.lang')?></label><select name="lang"><?php foreach($langs as $id=>$m):?><option value="<?=h($id)?>"<?=$id===($in('lang')?:$cur)?' selected':''?>><?=h($m['name']??$id)?></option><?php endforeach;?></select>
<label><?=t('install.tz')?></label><select name="tz"><?php foreach(tz_choices() as $z):?><option value="<?=h($z)?>"<?=$z===($in('tz')?:app_tz_name())?' selected':''?>><?=h($z)?></option><?php endforeach;?></select>
<label><?=t('install.cal')?></label><select name="cal"><option value="jalali"<?=($in('cal')?:app_cal())==='jalali'?' selected':''?>><?=t('set.cal.jalali')?></option><option value="gregorian"<?=($in('cal')?:app_cal())==='gregorian'?' selected':''?>><?=t('set.cal.greg')?></option></select>
<button class="btn block"<?=$ready?'':' disabled'?>><?=t('install.go')?></button>
</form>
<?php endif;?></div></div></body></html>
