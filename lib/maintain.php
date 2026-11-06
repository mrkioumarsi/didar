<?php
function app_version(){
 $v=trim((string)@file_get_contents(dirname(__DIR__).'/VERSION'));
 return preg_match('/^\d+\.\d+\.\d+$/',$v)?$v:'0.0.0';
}
function didar_fn($name){
 if(!function_exists($name)) return false;
 $d=strtolower((string)ini_get('disable_functions'));
 return !preg_match('/\b'.preg_quote($name,'/').'\b/',$d);
}
function didar_app(){
 $d=getenv('DIDAR_APP');
 if(!is_string($d)||!preg_match('#^/[A-Za-z0-9._/-]+$#',$d)) $d=dirname(__DIR__);
 $d=rtrim(str_replace('\\','/',$d),'/');
 return is_file($d.'/api.php')?$d:'';
}
function gh_body($url,$timeout=15){
 if(!preg_match('#^https://api\.github\.com/repos/mrkioumarsi/didar/#',$url)) return null;
 $head="User-Agent: Didar\r\nAccept: application/vnd.github+json\r\n";
 if(function_exists('curl_init')){
  $ch=curl_init($url);
  $opt=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_USERAGENT=>'Didar',CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json']];
  if(defined('CURLPROTO_HTTPS')) $opt[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
  curl_setopt_array($ch,$opt);
  $b=curl_exec($ch);
  $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  return $code===200&&is_string($b)&&$b!==''?$b:null;
 }
 $ctx=stream_context_create(['http'=>['timeout'=>$timeout,'header'=>$head,'ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
 $b=@file_get_contents($url,false,$ctx);
 return is_string($b)&&$b!==''?$b:null;
}
function update_remote(){
 $j=json_decode((string)gh_body('https://api.github.com/repos/mrkioumarsi/didar/releases/latest',8),true);
 if(!is_array($j)||empty($j['tag_name'])) return null;
 if(!preg_match('/^v(\d+\.\d+\.\d+)$/',(string)$j['tag_name'],$m)) return null;
 return ['tag'=>'v'.$m[1],'ver'=>$m[1]];
}
function update_can(){
 if(is_file('/usr/local/sbin/didar-update')&&is_executable('/usr/local/sbin/didar-update')&&didar_fn('exec')) return 'sudo';
 $root=dirname(__DIR__);
 if(is_writable($root)&&is_writable($root.'/api.php')&&class_exists('ZipArchive')) return 'write';
 return '';
}
function update_info($force=false){
 $cur=app_version();
 $root=dirname(__DIR__);
 $cache=$root.'/storage/update.json';
 if(!$force&&is_file($cache)&&time()-filemtime($cache)<900){
  $j=json_decode((string)@file_get_contents($cache),true);
  if(is_array($j)&&($j['cur']??'')===$cur&&!empty($j['ok'])){$j['can']=update_can();return $j;}
 }
 $rel=update_remote();
 $out=['ok'=>false,'cur'=>$cur,'latest'=>'','newer'=>false,'can'=>update_can()];
 if(!$rel){$out['error']='net';return $out;}
 $out['ok']=true;
 $out['latest']=$rel['ver'];
 $out['newer']=version_compare($rel['ver'],$cur,'>');
 if(is_dir($root.'/storage')&&is_writable($root.'/storage')) @file_put_contents($cache,json_encode($out),LOCK_EX);
 return $out;
}
function update_skip($rel){
 if($rel==='config.local.php') return true;
 if($rel==='storage'||str_starts_with($rel,'storage/')) return true;
 if($rel==='assets/avatars'||str_starts_with($rel,'assets/avatars/')) return true;
 if($rel==='.git'||str_starts_with($rel,'.git/')) return true;
 return false;
}
function didar_rrmdir($dir){
 $dir=str_replace('\\','/',$dir);
 if(!is_dir($dir)||!str_contains($dir,'didar-up-')) return;
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $f){$p=$f->getPathname();if($f->isDir()&&!$f->isLink())@rmdir($p);else @unlink($p);}
 @rmdir($dir);
}
function gh_save($url,$dest){
 if(!preg_match('#^https://codeload\.github\.com/mrkioumarsi/didar/zip/refs/tags/v\d+\.\d+\.\d+$#',$url)) return false;
 if(!function_exists('curl_init')) return false;
 $fh=@fopen($dest,'wb');
 if(!$fh) return false;
 $ch=curl_init($url);
 $opt=[CURLOPT_FILE=>$fh,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_TIMEOUT=>120,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_USERAGENT=>'Didar',CURLOPT_FAILONERROR=>true];
 if(defined('CURLPROTO_HTTPS')){
  $opt[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;
  if(defined('CURLOPT_REDIR_PROTOCOLS')) $opt[CURLOPT_REDIR_PROTOCOLS]=CURLPROTO_HTTPS;
 }
 curl_setopt_array($ch,$opt);
 $ok=curl_exec($ch);
 $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
 curl_close($ch);
 fclose($fh);
 if(!$ok||($code!==200&&$code!==0)){@unlink($dest);return false;}
 $n=@filesize($dest);
 if($n<100||$n>40*1048576){@unlink($dest);return false;}
 return true;
}
function update_unzip($file){
 if(!class_exists('ZipArchive')) return '';
 $zip=new ZipArchive();
 if($zip->open($file)!==true) return '';
 $tmp=rtrim(sys_get_temp_dir(),'\\/').'/didar-up-'.bin2hex(random_bytes(6));
 if(!@mkdir($tmp,0700,true)){$zip->close();return '';}
 $top='';
 for($i=0;$i<$zip->numFiles;$i++){
  $name=str_replace('\\','/',(string)$zip->getNameIndex($i));
  if($name===''||str_contains($name,'..')||str_starts_with($name,'/')||str_contains($name,"\0")){$zip->close();didar_rrmdir($tmp);return '';}
  $one=explode('/',$name)[0];
  if($top==='') $top=$one;
  if($one!==$top){$zip->close();didar_rrmdir($tmp);return '';}
 }
 if($top===''||!$zip->extractTo($tmp)){$zip->close();didar_rrmdir($tmp);return '';}
 $zip->close();
 $src=$tmp.'/'.$top;
 if(!is_file($src.'/api.php')){didar_rrmdir($tmp);return '';}
 return $src;
}
function update_copy_tree($src,$dst){
 $src=rtrim(str_replace('\\','/',$src),'/');
 $dst=rtrim(str_replace('\\','/',$dst),'/');
 $real=realpath($dst);
 if(!$real) return false;
 $real=rtrim(str_replace('\\','/',$real),'/');
 $keep=[];
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
 foreach($it as $f){
  $rel=substr(str_replace('\\','/',$f->getPathname()),strlen($src)+1);
  if($rel===false||$rel===''||str_contains($rel,'..')) return false;
  if(update_skip($rel)) continue;
  $to=$dst.'/'.$rel;
  if($f->isDir()){if(!is_dir($to)&&!@mkdir($to,0755,true)) return false;continue;}
  if(!$f->isFile()||$f->isLink()) continue;
  $parent=dirname($to);
  if(!is_dir($parent)&&!@mkdir($parent,0755,true)) return false;
  $pr=realpath($parent);
  if(!$pr) return false;
  $pr=rtrim(str_replace('\\','/',$pr),'/');
  if($pr!==$real&&!str_starts_with($pr,$real.'/')) return false;
  if(!@copy($f->getPathname(),$to)) return false;
  $keep[$rel]=1;
 }
 $it2=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dst,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it2 as $f){
  $rel=substr(str_replace('\\','/',$f->getPathname()),strlen($dst)+1);
  if($rel===false||$rel===''||update_skip($rel)) continue;
  if($f->isFile()&&!isset($keep[$rel])) @unlink($f->getPathname());
  elseif($f->isDir()&&!$f->isLink()) @rmdir($f->getPathname());
 }
 return is_file($dst.'/api.php')&&is_file($dst.'/VERSION');
}
function didar_fix_tree($root){
 if(!didar_fn('exec')) return;
 $q=escapeshellarg($root);
 exec('chown -R root:www-data '.$q.' && find '.$q.' -type d -exec chmod 755 {} + && find '.$q.' -type f -exec chmod 644 {} +');
 exec('chown -R www-data:www-data '.escapeshellarg($root.'/storage').' '.escapeshellarg($root.'/assets/avatars'));
 exec('chmod 750 '.escapeshellarg($root.'/storage').' ; chmod 755 '.escapeshellarg($root.'/assets/avatars'));
 if(is_file($root.'/config.local.php')) exec('chown root:www-data '.escapeshellarg($root.'/config.local.php').' && chmod 640 '.escapeshellarg($root.'/config.local.php'));
 foreach([$root.'/assets/icon-192.png',$root.'/assets/icon-512.png'] as $ic) if(is_file($ic)) exec('chown www-data:www-data '.escapeshellarg($ic).' && chmod 664 '.escapeshellarg($ic));
}
function didar_domain_file(){
 $f='/etc/didar/domain';
 if(is_file($f)&&preg_match('/^[A-Za-z0-9.-]{1,253}$/',trim((string)@file_get_contents($f)))) return;
 if(!is_dir('/etc/didar')||!is_writable('/etc/didar')) return;
 $ng=@file_get_contents('/etc/nginx/sites-available/didar');
 if(!is_string($ng)||!preg_match('/server_name\s+([A-Za-z0-9.-]{1,253})\s*;/',$ng,$m)) return;
 if($m[1]==='_'||$m[1]==='localhost') return;
 @file_put_contents($f,$m[1]."\n");
 @chmod($f,0644);
}
function didar_sudoers(){
 $root=dirname(__DIR__);
 if(is_dir('/usr/local/sbin')&&is_writable('/usr/local/sbin')){
  foreach(['didar-update.sh'=>'/usr/local/sbin/didar-update','didar-renew.sh'=>'/usr/local/sbin/didar-renew'] as $src=>$dst){
   if(!is_file($root.'/'.$src)) continue;
   @copy($root.'/'.$src,$dst);
   @chmod($dst,0755);
  }
 }
 didar_domain_file();
 if(!is_file('/usr/local/sbin/didar-update')||!is_dir('/etc/sudoers.d')||!is_writable('/etc/sudoers.d')) return;
 $line='www-data ALL=(root) NOPASSWD: /usr/local/sbin/didar-update';
 if(is_file('/usr/local/sbin/didar-renew')) $line.=', /usr/local/sbin/didar-renew';
 $sf='/etc/sudoers.d/didar';
 $cur=is_file($sf)?trim((string)@file_get_contents($sf)):'';
 if($cur!==$line){
  if(@file_put_contents($sf,$line."\n")===false) return;
  @chmod($sf,0440);
  $code=1;$o=[];
  if(didar_fn('exec')) exec('visudo -cf '.escapeshellarg($sf).' >/dev/null 2>&1',$o,$code);
  if($code!==0){
   if($cur!=='') @file_put_contents($sf,$cur."\n");
   else @unlink($sf);
   return;
  }
 }
 if(didar_fn('exec')&&is_executable('/usr/bin/apt-get')&&!is_executable('/usr/bin/ffmpeg')) exec('bash -c '.escapeshellarg('sleep 3; command -v ffmpeg >/dev/null 2>&1 || DEBIAN_FRONTEND=noninteractive apt-get install -y ffmpeg >/dev/null 2>&1').' >/dev/null 2>&1 &');
}
function nginx_hide(){
 didar_sudoers();
 $root=dirname(__DIR__);
 if(is_file($root.'/schema.sql')&&is_dir('/etc/didar')&&is_writable('/etc/didar')){
  @copy($root.'/schema.sql','/etc/didar/schema.sql');
  @chmod('/etc/didar/schema.sql',0600);
 }
 $sh=$root.'/didar-update.sh';
 if(!is_file($sh))$sh=$root.'/install/didar-update.sh';
 if(is_file($sh)&&is_dir('/usr/local/sbin')&&is_writable('/usr/local/sbin')){
  @copy($sh,'/usr/local/sbin/didar-update');
  @chmod('/usr/local/sbin/didar-update',0755);
 }
 $ver=PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
 foreach(['fpm','cli'] as $k){
  $ini="/etc/php/$ver/$k/conf.d/99-didar.ini";
  if(!is_file($ini)||!is_writable($ini))continue;
  $txt=(string)@file_get_contents($ini);
  if($txt!==''&&!str_contains($txt,'expose_php'))@file_put_contents($ini,rtrim($txt)."\nexpose_php=Off\n");
 }
 $f='/etc/nginx/sites-available/didar';
 if(!is_file($f)||!is_writable($f))return;
 $t=@file_get_contents($f);
 if(!is_string($t)||$t==='')return;
 $n=str_replace(['fastcgi_read_timeout 120s;','fastcgi_read_timeout 180s;'],['fastcgi_read_timeout 600s;','fastcgi_read_timeout 600s;'],$t);
 $add='';
 foreach(['location = /schema.sql { deny all; }','location = /ubuntu.sh { deny all; }','location = /didar-update.sh { deny all; }','location = /didar-renew.sh { deny all; }','location = /install.php { deny all; }'] as $line){
  if(!str_contains($n,$line))$add.=" ".$line."\n";
 }
 if($add!=='')$n=preg_replace('/\n}\s*$/',"\n".$add."}\n",$n,1);
 if(!is_string($n)||$n===$t)return;
 @file_put_contents($f,$n);
 if(getenv('DIDAR_UPDATE')==='1')return;
 if(didar_fn('exec'))exec('bash -c '.escapeshellarg('sleep 2; systemctl reload php'.$ver.'-fpm >/dev/null 2>&1 || true; nginx -t >/dev/null 2>&1 && systemctl reload nginx >/dev/null 2>&1 || true').' >/dev/null 2>&1 &');
}
function update_finish($root){
 if(is_dir('/etc/didar')&&is_writable('/etc/didar')){
  foreach([$root.'/schema.sql',$root.'/install/schema.sql'] as $s){
   if(is_file($s)){@copy($s,'/etc/didar/schema.sql');@chmod('/etc/didar/schema.sql',0600);break;}
  }
 }
 foreach([$root.'/didar-update.sh',$root.'/install/didar-update.sh'] as $sh){
  if(is_file($sh)&&is_dir('/usr/local/sbin')&&is_writable('/usr/local/sbin')){
   @copy($sh,'/usr/local/sbin/didar-update');
   @chmod('/usr/local/sbin/didar-update',0755);
   break;
  }
 }
 if(function_exists('posix_geteuid')&&posix_geteuid()===0)nginx_hide();
 if(is_file($root.'/storage/installed.lock')){
  @unlink($root.'/install.php');
  @unlink($root.'/schema.sql');
  @unlink($root.'/install/install.php');
  @unlink($root.'/install/schema.sql');
 }
 @unlink($root.'/storage/update.json');
 if(function_exists('opcache_reset')) @opcache_reset();
}
function update_pull($root,$reinstall=false){
 @set_time_limit(180);
 $cur=app_version();
 if(!preg_match('/^\d+\.\d+\.\d+$/',$cur)) return false;
 $nonce='';
 if($reinstall){
  $nonce=trim((string)@file_get_contents($root.'/storage/reinstall.req'));
  if(!preg_match('/^[a-f0-9]{16}$/',$nonce)) return false;
  $tag='v'.$cur;
 }else{
  $rel=update_remote();
  if(!$rel) return false;
  if(version_compare($rel['ver'],$cur,'<=')) return true;
  $tag=$rel['tag'];
 }
 $zip=rtrim(sys_get_temp_dir(),'\\/').'/didar-up-'.bin2hex(random_bytes(4)).'.zip';
 $src='';
 $ok=false;
 if(gh_save('https://codeload.github.com/mrkioumarsi/didar/zip/refs/tags/'.$tag,$zip)){
  $src=update_unzip($zip);
  if($src!==''&&update_copy_tree($src,$root)) $ok=true;
 }
 if($src!=='') didar_rrmdir(dirname($src));
 @unlink($zip);
 if(!$ok) return false;
 if($reinstall){
  $got=trim((string)@file_get_contents($root.'/VERSION'));
  if($got!==$cur) return false;
  if(@file_put_contents($root.'/storage/reinstall.stamp',$nonce,LOCK_EX)===false) return false;
  @unlink($root.'/storage/reinstall.req');
 }
 update_finish($root);
 return is_file($root.'/VERSION');
}
function update_apply_root(){
 if(PHP_SAPI!=='cli') return false;
 if(function_exists('posix_geteuid')&&posix_geteuid()!==0) return false;
 $root=didar_app();
 if($root==='') return false;
 if(!update_pull($root,getenv('DIDAR_REINSTALL')==='1')) return false;
 didar_fix_tree($root);
 if(getenv('DIDAR_UPDATE')==='1') return true;
 if(didar_fn('exec')){
  $ver=PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
  exec('systemctl reload php'.$ver.'-fpm >/dev/null 2>&1');
 }
 return true;
}
function update_apply($reinstall=false){
 @set_time_limit(180);
 if($reinstall){
  $cur=app_version();
  $root=dirname(__DIR__);
  if(!preg_match('/^\d+\.\d+\.\d+$/',$cur)||!is_dir($root.'/storage')) return ['ok'=>0,'error'=>'fail'];
  $nonce=bin2hex(random_bytes(8));
  if(@file_put_contents($root.'/storage/reinstall.req',$nonce,LOCK_EX)===false) return ['ok'=>0,'error'=>'perm'];
  $how=update_can();
  $stamp='';
  if($how==='sudo'){
   $out=[];$code=1;
   exec('sudo -n /usr/local/sbin/didar-update reinstall 2>&1',$out,$code);
   $stamp=is_file($root.'/storage/reinstall.stamp')?trim((string)@file_get_contents($root.'/storage/reinstall.stamp')):'';
   @unlink($root.'/storage/reinstall.req');
   @unlink($root.'/storage/reinstall.stamp');
   if($code!==0||!hash_equals($nonce,$stamp)){
    if(function_exists('log_err')) log_err(new RuntimeException('reinstall '.substr(implode(' ',$out),0,300)));
    return ['ok'=>0,'error'=>'fail'];
   }
   return ['ok'=>1,'cur'=>app_version()];
  }
  if($how==='write'){
   $ok=update_pull($root,true);
   $stamp=is_file($root.'/storage/reinstall.stamp')?trim((string)@file_get_contents($root.'/storage/reinstall.stamp')):'';
   @unlink($root.'/storage/reinstall.req');
   @unlink($root.'/storage/reinstall.stamp');
   if(!$ok||!hash_equals($nonce,$stamp)) return ['ok'=>0,'error'=>'fail'];
   if(function_exists('ensure_schema')){try{ensure_schema();}catch(Throwable $e){}}
   return ['ok'=>1,'cur'=>app_version()];
  }
  @unlink($root.'/storage/reinstall.req');
  return ['ok'=>0,'error'=>'perm'];
 }
 $info=update_info(true);
 if(empty($info['ok'])) return ['ok'=>0,'error'=>'net'];
 if(empty($info['newer'])) return ['ok'=>1,'cur'=>$info['cur'],'latest'=>$info['latest']];
 $how=update_can();
 if($how==='sudo'){
  $out=[];$code=1;
  exec('sudo -n /usr/local/sbin/didar-update 2>&1',$out,$code);
  $now=app_version();
  if($code!==0&&version_compare($now,(string)$info['latest'],'<')){
   if(function_exists('log_err')) log_err(new RuntimeException('update '.substr(implode(' ',$out),0,300)));
   return ['ok'=>0,'error'=>'fail'];
  }
  return ['ok'=>1,'cur'=>$now,'latest'=>$info['latest']];
 }
 if($how==='write'){
  $root=dirname(__DIR__);
  if(!update_pull($root)) return ['ok'=>0,'error'=>'fail'];
  if(function_exists('ensure_schema')){try{ensure_schema();}catch(Throwable $e){}}
  return ['ok'=>1,'cur'=>app_version(),'latest'=>$info['latest']];
 }
 return ['ok'=>0,'error'=>'perm'];
}
function sql_ident($s){return preg_match('/^[A-Za-z0-9_]+$/',$s)?'`'.$s.'`':'';}
function backup_php($path){
 $fh=@fopen($path,'wb');
 if(!$fh) return false;
 fwrite($fh,"SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
 try{
  db()->beginTransaction();
  $tables=db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
  foreach($tables as $t){
   $name=$t[0]??'';
   $id=sql_ident($name);
   if($id==='') continue;
   $cr=db()->query('SHOW CREATE TABLE '.$id)->fetch(PDO::FETCH_NUM);
   if(empty($cr[1])) continue;
   fwrite($fh,"DROP TABLE IF EXISTS {$id};\n".$cr[1].";\n");
   $q=db()->query('SELECT * FROM '.$id);
   while($row=$q->fetch(PDO::FETCH_ASSOC)){
    $cols=[];$vals=[];
    foreach($row as $k=>$v){$ik=sql_ident((string)$k);if($ik==='') continue 2;$cols[]=$ik;$vals[]=$v===null?'NULL':db()->quote($v);}
    if($cols) fwrite($fh,'INSERT INTO '.$id.' ('.implode(',',$cols).') VALUES ('.implode(',',$vals).");\n");
   }
  }
  db()->commit();
 }catch(Throwable $e){
  try{db()->rollBack();}catch(Throwable $e2){}
  fclose($fh);
  @unlink($path);
  return false;
 }
 fwrite($fh,"SET FOREIGN_KEY_CHECKS=1;\n");
 fclose($fh);
 return is_file($path)&&filesize($path)>40;
}
function backup_make(){
 global $C;
 $dir=dirname(__DIR__).'/storage';
 if(!is_dir($dir)||!is_writable($dir)) return '';
 foreach(glob($dir.'/bak-*.sql')?:[] as $old) if(is_file($old)&&filemtime($old)<time()-3600) @unlink($old);
 $path=$dir.'/bak-'.bin2hex(random_bytes(8)).'.sql';
 $bin='';
 if(preg_match('/^[A-Za-z0-9_]+$/',(string)$C['db_name'])&&preg_match('/^[A-Za-z0-9._:-]+$/',(string)$C['db_host'])){
 foreach(['/usr/bin/mariadb-dump','/usr/bin/mysqldump'] as $b) if(is_executable($b)){$bin=$b;break;}
 }
 if($bin!==''&&didar_fn('proc_open')){
  $cnf=$dir.'/bak-'.bin2hex(random_bytes(4)).'.cnf';
  $esc=function($s){return str_replace(['\\','"'],['\\\\','\\"'],str_replace(["\r","\n"],'',(string)$s));};
  $okc=@file_put_contents($cnf,"[client]\nuser=\"".$esc($C['db_user'])."\"\npassword=\"".$esc($C['db_pass'])."\"\nhost=\"".$esc($C['db_host'])."\"\n");
  if($okc!==false){
   @chmod($cnf,0600);
   try{
    $spec=[0=>['pipe','r'],1=>['file',$path,'w'],2=>['pipe','w']];
    $cmd=[$bin,'--defaults-extra-file='.$cnf,'--single-transaction','--skip-lock-tables','--no-tablespaces','--default-character-set=utf8mb4',$C['db_name']];
    $proc=@proc_open($cmd,$spec,$pipes);
    if(is_resource($proc)){
     fclose($pipes[0]);
     stream_get_contents($pipes[2]);
     fclose($pipes[2]);
     $code=proc_close($proc);
     $head=(string)@file_get_contents($path,false,null,0,4000);
     if(is_file($path)&&filesize($path)>80&&($code===0||$code===2)&&str_contains($head,'CREATE')) return $path;
    }
   }finally{@unlink($cnf);}
  }
  @unlink($path);
 }
 return backup_php($path)?$path:'';
}
function backup_send(){
 @set_time_limit(180);
 $path=backup_make();
 if($path===''||!is_file($path)) fail(t('bak.fail'));
 $name='didar-'.date('Ymd-His').'.sql';
 header('Content-Type: application/sql; charset=utf-8');
 header('Content-Disposition: attachment; filename="'.$name.'"');
 header('Content-Length: '.filesize($path));
 header('X-Content-Type-Options: nosniff');
 header('Cache-Control: no-store');
 readfile($path);
 @unlink($path);
 exit;
}
function restore_apply($src){
 global $C;
 @set_time_limit(0);
 if(!is_file($src)||!is_readable($src)) return false;
 $sz=filesize($src);
 if($sz<40||$sz>60*1048576) return false;
 if(!preg_match('/^[A-Za-z0-9_]+$/',(string)$C['db_name'])||!preg_match('/^[A-Za-z0-9._:-]+$/',(string)$C['db_host'])) return false;
 $fh=@fopen($src,'rb');
 if(!$fh) return false;
 $head='';
 while(!feof($fh)){
  $chunk=fread($fh,1048576);
  if($chunk===false||$chunk==='') break;
  if($head==='') $head=substr($chunk,0,8192);
  if(str_contains($chunk,"\0")){fclose($fh);return false;}
 }
 fclose($fh);
 if($head===''||!preg_match('/CREATE TABLE|INSERT INTO|DROP TABLE/i',$head)) return false;
 $bin='';
 foreach(['/usr/bin/mariadb','/usr/bin/mysql'] as $b) if(is_executable($b)){$bin=$b;break;}
 if($bin===''||!didar_fn('proc_open')) return false;
 $dir=dirname(__DIR__).'/storage';
 $cnf=$dir.'/bak-'.bin2hex(random_bytes(4)).'.cnf';
 $esc=function($s){return str_replace(['\\','"'],['\\\\','\\"'],str_replace(["\r","\n"],'',(string)$s));};
 if(@file_put_contents($cnf,"[client]\nuser=\"".$esc($C['db_user'])."\"\npassword=\"".$esc($C['db_pass'])."\"\nhost=\"".$esc($C['db_host'])."\"\n")===false) return false;
 @chmod($cnf,0600);
 $code=1;
 try{
  $spec=[0=>['file',$src,'r'],1=>['pipe','w'],2=>['pipe','w']];
  $proc=@proc_open([$bin,'--defaults-extra-file='.$cnf,'--default-character-set=utf8mb4',$C['db_name']],$spec,$pipes);
  if(!is_resource($proc)) return false;
  stream_get_contents($pipes[1]);
  stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $code=proc_close($proc);
 }finally{@unlink($cnf);}
 return $code===0;
}
function ssl_renew(){
 @set_time_limit(180);
 if(!is_executable('/usr/local/sbin/didar-renew')||!didar_fn('exec')) fail(t('ssl.perm'));
 $out=[];$code=1;
 exec('sudo -n /usr/local/sbin/didar-renew 2>&1',$out,$code);
 if($code!==0){
  $s=implode(' ',$out);
  if(function_exists('log_err')) log_err(new RuntimeException('ssl '.substr($s,0,300)));
  if(stripos($s,'password')!==false||stripos($s,'not permitted')!==false||stripos($s,'not allowed')!==false||stripos($s,'a terminal is required')!==false) fail(t('ssl.perm'));
  fail(t('ssl.fail'));
 }
 out(['ok'=>1]);
}
function rec_send($id,$dl){
 if($dl){http_response_code(403);die(t('err.forbidden'));}
 $r=q("SELECT * FROM recordings WHERE id=?",[$id])->fetch();
 if(!$r||!room_access((int)$r['room_id'])){http_response_code(403);die(t('err.forbidden'));}
 $fn=basename($r['file']);
 if(!preg_match('/^rec_\d+_\d+_[a-f0-9]+\.(webm|mp4)$/',$fn)){http_response_code(403);die(t('err.forbidden'));}
 $fp=dirname(__DIR__).'/storage/'.$fn;
 if(!is_file($fp)||filesize($fp)<1){http_response_code(404);die(t('err.nofile'));}
 while(ob_get_level()) @ob_end_clean();
 @ini_set('zlib.output_compression','0');
 $ext=strtolower(pathinfo($fp,PATHINFO_EXTENSION));
 $size=filesize($fp);
 header_remove('Pragma');
 header_remove('Expires');
 header_remove('Cache-Control');
 header('Content-Type: '.($ext==='mp4'?'video/mp4':'video/webm'));
 header('Content-Disposition: inline; filename="'.$fn.'"');
 header('X-Content-Type-Options: nosniff');
 header('Cache-Control: private, max-age=86400');
 header('Content-Length: '.$size);
 http_response_code(200);
 $fh=fopen($fp,'rb');
 if(!$fh){http_response_code(404);die(t('err.nofile'));}
 while(!feof($fh)&&!connection_aborted()){$buf=fread($fh,1048576);if($buf===false||$buf==='')break;echo $buf;}
 fclose($fh);
 exit;
}
