<?php
require 'config.php';
function need_admin(){$u=user();if(!$u||$u['role']!=='admin')fail(t('err.forbidden'));return $u;}
function part(){
 $pid=(int)($_POST['pid']??0);
 if(empty($_SESSION['p'][$pid]))fail(t('err.session'));
 $p=q("SELECT p.*,(u.role='admin' OR IFNULL(m.is_manager,0)=1) mgr FROM participants p LEFT JOIN users u ON u.id=p.user_id LEFT JOIN room_members m ON m.room_id=p.room_id AND m.user_id=p.user_id WHERE p.id=?",[$pid])->fetch();
 if(!$p)fail(t('err.session'));
 $p['owner']=$p['user_id']?'u'.$p['user_id']:'g'.$p['id'];
 return $p;
}
function save_avatar($uid){
 if(empty($_FILES['f'])||$_FILES['f']['error'])fail(t('err.nofile'));
 if($_FILES['f']['size']>5*1048576)fail(t('err.filebig'));
 if(!function_exists('imagecreatefromstring'))fail(t('err.nogd'));
 $im=@imagecreatefromstring(file_get_contents($_FILES['f']['tmp_name']));
 if(!$im)fail(t('err.badimage'));
 $w=imagesx($im);$h=imagesy($im);$s=min($w,$h);
 $o=imagecreatetruecolor(256,256);
 imagefill($o,0,0,imagecolorallocate($o,255,255,255));
 imagecopyresampled($o,$im,0,0,intdiv($w-$s,2),intdiv($h-$s,2),256,256,$s,$s);
 $dir=__DIR__.'/assets/avatars';
 if(!is_dir($dir))@mkdir($dir,0755,true);
 $fn='u'.$uid.'_'.bin2hex(random_bytes(5)).'.jpg';
 if(!@imagejpeg($o,$dir.'/'.$fn,85))fail(t('err.nosave'));
 del_avatar($uid,false);
 q("UPDATE users SET avatar=? WHERE id=?",[$fn,$uid]);
 return $fn;
}
function del_avatar($uid,$clear=true){
 $old=q("SELECT avatar FROM users WHERE id=?",[$uid])->fetchColumn();
 if($old)@unlink(__DIR__.'/assets/avatars/'.basename($old));
 if($clear)q("UPDATE users SET avatar=NULL WHERE id=?",[$uid]);
}
function avatar_target(){
 $me=user()?:fail(t('err.session'));
 $id=(int)($_POST['id']??0);
 if($id&&$id!=$me['id']){if($me['role']!=='admin')fail(t('err.forbidden'));return $id;}
 return (int)$me['id'];
}
function clean_user($s){
 $s=trim((string)$s);
 $n=mb_strlen($s);
 if($n<3||$n>50)fail(t('err.username'));
 return $s;
}
function clean_domain($s){
 $s=trim((string)$s);
 if($s==='')return '';
 if(strlen($s)>200)fail(t('err.domain'));
 if(!preg_match('~^https?://[A-Za-z0-9._:-]+(/[^\s]*)?$~',$s)&&!preg_match('~^[A-Za-z0-9.-]+$~',$s))fail(t('err.domain'));
 return $s;
}
function clean_lk($s){
 $s=trim((string)$s);
 if($s==='')return '';
 if(!preg_match('~^wss?://[A-Za-z0-9._:-]+(/[^\s]*)?$~',$s))fail(t('err.lkurl'));
 return $s;
}
function apply_lang($uid){
 $c=lang_code($_POST['lang']??'');
 if($c)q("UPDATE users SET lang=? WHERE id=?",[$c,$uid]);
 return $c;
}
$a=$_REQUEST['action']??'';
$P=$_POST;
if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&!csrf_ok())fail(t('err.csrf'));
if(!in_array($a,['login','logout','join','lang_pick'],true))session_write_close();
try{
 if(file_exists(__DIR__.'/storage/installed.lock'))ensure_schema();
 switch($a){
 case 'lang_pick':
  $c=lang_code($P['lang']??'');
  if(!$c)fail(t('err.badlang'));
  lang_cookie($c);
  if($u=user())q("UPDATE users SET lang=? WHERE id=?",[$c,$u['id']]);
  out(['ok'=>1]);
 case 'login':
  if(login_blocked())fail(t('err.rate'));
  $u=q("SELECT * FROM users WHERE username=?",[trim($P['username']??'')])->fetch();
  if(!$u||!password_verify($P['password']??'',$u['pass'])){login_mark(false);fail(t('err.login'));}
  login_mark(true);
  session_regenerate_id(true);
  $_SESSION['uid']=$u['id'];
  $_SESSION['csrf']=bin2hex(random_bytes(16));
  auth_login($u['id']);
  $lg=lang_code($P['lang']??($_COOKIE['dlang']??''));
  if($lg){q("UPDATE users SET lang=? WHERE id=?",[$lg,$u['id']]);lang_cookie($lg);}
  out(['ok'=>1,'csrf'=>$_SESSION['csrf']]);
 case 'logout':
  auth_logout();
  $_SESSION=[];
  session_destroy();
  out(['ok'=>1]);
 case 'me':
  $u=user();
  $x=['user'=>$u,'brand'=>setting('brand'),'domain'=>setting('domain'),'cal'=>app_cal(),'tz'=>app_tz_name(),'lang'=>html_lang(),'langs'=>[],'pass_min'=>pass_min(),'pass_policy'=>pass_policy(),'mode'=>link_mode()];
  foreach(lang_list() as $id=>$m)$x['langs'][]=['id'=>$id,'name'=>$m['name']];
  if($u&&$u['role']==='admin')foreach(['stun','turn_url','turn_user','turn_pass','turn_secret','ice_relay','quality','lk_url','lk_key','lk_secret','calendar','timezone','default_lang'] as $k)$x[$k]=setting($k);
  out($x);
 case 'ice':need_admin();out(['ice'=>ice_servers()]);
 case 'node':
  if(!mon_node_ok()){http_response_code(404);exit;}
  out(mon_machine());
 case 'monitor':need_admin();out(mon_snapshot());
 case 'users_list':need_admin();out(['users'=>q("SELECT u.id,u.username,u.name,u.role,u.bio,u.avatar,u.lang,(SELECT COUNT(*) FROM room_members m WHERE m.user_id=u.id) rooms FROM users u ORDER BY u.id")->fetchAll()]);
 case 'users_search':
  need_admin();
  $s='%'.trim($P['q']??'').'%';
  out(['users'=>q("SELECT id,name,username,avatar FROM users WHERE role<>'admin' AND (name LIKE ? OR username LIKE ?) AND id NOT IN (SELECT user_id FROM room_members WHERE room_id=?) ORDER BY name LIMIT 40",[$s,$s,(int)($P['room_id']??0)])->fetchAll()]);
 case 'user_add':
  need_admin();
  $name=mb_substr(trim($P['name']??''),0,100);
  if($name==='')fail(t('err.name'));
  pass_fail($P['password']??'');
  try{q("INSERT INTO users(username,pass,name,role,lang) VALUES(?,?,?,?,?)",[clean_user($P['username']??''),password_hash($P['password'],PASSWORD_DEFAULT),$name,$P['role']==='admin'?'admin':'user',lang_code($P['lang']??'')]);}
  catch(Exception $e){fail(t('err.dupuser'));}
  out(['ok'=>1]);
 case 'user_del':
  $u=need_admin();
  if($u['id']==$P['id'])fail(t('err.selfdel'));
  del_avatar((int)$P['id'],false);
  q("DELETE FROM users WHERE id=?",[$P['id']]);
  q("DELETE FROM room_members WHERE user_id=?",[$P['id']]);
  q("DELETE FROM auth_tokens WHERE user_id=?",[$P['id']]);
  out(['ok'=>1]);
 case 'profile_save':
  $u=user()?:fail(t('err.session'));
  $name=mb_substr(trim($P['name']??''),0,100)?:$u['name'];
  q("UPDATE users SET name=?,bio=? WHERE id=?",[$name,mb_substr(trim($P['bio']??''),0,200),$u['id']]);
  $lg=apply_lang($u['id']);
  if($lg)lang_cookie($lg);
  if(($P['password']??'')!==''){
   $row=q("SELECT pass FROM users WHERE id=?",[$u['id']])->fetch();
   if(!password_verify($P['current']??'',$row['pass']))fail(t('err.badpass'));
   pass_fail($P['password']);
   q("UPDATE users SET pass=? WHERE id=?",[password_hash($P['password'],PASSWORD_DEFAULT),$u['id']]);
   q("DELETE FROM auth_tokens WHERE user_id=?",[$u['id']]);
   auth_login($u['id']);
  }
  out(['ok'=>1,'reload'=>$lg && $lg!==html_lang()?1:0]);
 case 'avatar_set':$id=avatar_target();out(['avatar'=>save_avatar($id)]);
 case 'avatar_del':$id=avatar_target();del_avatar($id);out(['ok'=>1]);
 case 'user_get':
  need_admin();
  $row=q("SELECT id,username,name,role,bio,avatar,lang FROM users WHERE id=?",[(int)$P['id']])->fetch()?:fail(t('err.nouser'));
  out(['user'=>$row,'rooms'=>q("SELECT r.id,r.name,(m.user_id IS NOT NULL) member,IFNULL(m.is_manager,0) is_manager FROM rooms r LEFT JOIN room_members m ON m.room_id=r.id AND m.user_id=? ORDER BY r.id DESC",[$row['id']])->fetchAll()]);
 case 'user_set':
  $me=need_admin();
  $id=(int)$P['id'];
  q("SELECT 1 FROM users WHERE id=?",[$id])->fetch()?:fail(t('err.nouser'));
  $role=($P['role']??'')==='admin'?'admin':'user';
  if($id==$me['id'])$role='admin';
  $name=mb_substr(trim($P['name']??''),0,100);
  if($name==='')fail(t('err.name'));
  q("UPDATE users SET name=?,bio=?,role=? WHERE id=?",[$name,mb_substr(trim($P['bio']??''),0,200),$role,$id]);
  apply_lang($id);
  if(($P['password']??'')!==''){pass_fail($P['password']);q("UPDATE users SET pass=? WHERE id=?",[password_hash($P['password'],PASSWORD_DEFAULT),$id]);q("DELETE FROM auth_tokens WHERE user_id=?",[$id]);}
  out(['ok'=>1]);
 case 'user_room_set':
  need_admin();
  $uid=(int)$P['user_id'];$rid=(int)$P['room_id'];
  if((int)$P['member'])q("INSERT INTO room_members(room_id,user_id,is_manager) VALUES(?,?,?) ON DUPLICATE KEY UPDATE is_manager=VALUES(is_manager)",[$rid,$uid,(int)($P['is_manager']??0)]);
  else q("DELETE FROM room_members WHERE room_id=? AND user_id=?",[$rid,$uid]);
  out(['ok'=>1]);
 case 'rooms_list':
  $u=user()?:fail(t('err.session'));
  $rs=$u['role']==='admin'
   ?q("SELECT r.*,1 mgr,(SELECT COUNT(*) FROM room_members x WHERE x.room_id=r.id) members,(SELECT COUNT(*) FROM participants p WHERE p.room_id=r.id AND p.left_at IS NULL AND p.last_seen>NOW()-INTERVAL 20 SECOND) online FROM rooms r ORDER BY r.id DESC")->fetchAll()
   :q("SELECT r.*,m.is_manager mgr,(SELECT COUNT(*) FROM room_members x WHERE x.room_id=r.id) members,(SELECT COUNT(*) FROM participants p WHERE p.room_id=r.id AND p.left_at IS NULL AND p.last_seen>NOW()-INTERVAL 20 SECOND) online FROM rooms r JOIN room_members m ON m.room_id=r.id WHERE m.user_id=? ORDER BY r.id DESC",[$u['id']])->fetchAll();
  foreach($rs as &$r)$r['link']=base().'/room.php?c='.$r['code'];
  out(['rooms'=>$rs]);
 case 'room_add':
  need_admin();
  $name=trim($P['name']??'');
  if($name==='')fail(t('err.name'));
  q("INSERT INTO rooms(code,name,description,guest_enabled,chat_enabled,rec_enabled) VALUES(?,?,?,?,?,?)",[bin2hex(random_bytes(4)),mb_substr($name,0,100),trim($P['description']??''),(int)($P['guest_enabled']??0),(int)($P['chat_enabled']??0),(int)($P['rec_enabled']??1)]);
  out(['id'=>(int)db()->lastInsertId()]);
 case 'room_del':
  need_admin();
  foreach(['rooms'=>'id','room_members'=>'room_id','participants'=>'room_id','signals'=>'room_id','chat'=>'room_id'] as $tb=>$c)q("DELETE FROM $tb WHERE $c=?",[$P['id']]);
  foreach(q("SELECT file FROM recordings WHERE room_id=?",[$P['id']])->fetchAll() as $f)@unlink(__DIR__.'/storage/'.basename($f['file']));
  q("DELETE FROM recordings WHERE room_id=?",[$P['id']]);
  out(['ok'=>1]);
 case 'room_set':
  if(!can_manage($P['id']))fail(t('err.forbidden'));
  if(isset($P['name'])){if(trim($P['name'])==='')fail(t('err.name'));q("UPDATE rooms SET name=? WHERE id=?",[mb_substr(trim($P['name']),0,100),$P['id']]);}
  if(isset($P['description']))q("UPDATE rooms SET description=? WHERE id=?",[trim($P['description']),$P['id']]);
  foreach(['guest_enabled','chat_enabled','rec_enabled'] as $f)if(isset($P[$f]))q("UPDATE rooms SET $f=? WHERE id=?",[(int)$P[$f],$P['id']]);
  out(['ok'=>1]);
 case 'members':
  if(!can_manage($P['room_id']))fail(t('err.forbidden'));
  out(['members'=>q("SELECT u.id,u.name,u.username,u.avatar,m.is_manager,m.can_mic,m.can_cam,m.can_screen,m.can_board FROM room_members m JOIN users u ON u.id=m.user_id WHERE m.room_id=? ORDER BY u.name",[$P['room_id']])->fetchAll()]);
 case 'member_add':
  need_admin();
  foreach(array_filter(explode(',',$P['user_ids']??'')) as $uid)q("INSERT IGNORE INTO room_members(room_id,user_id,is_manager) VALUES(?,?,?)",[$P['room_id'],(int)$uid,(int)($P['is_manager']??0)]);
  out(['ok'=>1]);
 case 'member_del':
  need_admin();
  q("DELETE FROM room_members WHERE room_id=? AND user_id=?",[$P['room_id'],$P['user_id']]);
  out(['ok'=>1]);
 case 'member_set':
  if(!can_manage($P['room_id']))fail(t('err.forbidden'));
  q("UPDATE room_members SET can_mic=?,can_cam=?,can_screen=?,can_board=? WHERE room_id=? AND user_id=?",[(int)$P['can_mic'],(int)$P['can_cam'],(int)$P['can_screen'],(int)$P['can_board'],$P['room_id'],$P['user_id']]);
  if(user()['role']==='admin')q("UPDATE room_members SET is_manager=? WHERE room_id=? AND user_id=?",[(int)$P['is_manager'],$P['room_id'],$P['user_id']]);
  out(['ok'=>1]);
 case 'settings_save':
  need_admin();
  $mode=($P['link_mode']??'')==='sfu'?'sfu':'p2p';
  $cal=($P['calendar']??'')==='gregorian'?'gregorian':'jalali';
  $pol=in_array($P['pass_policy']??'',['low','mid','high'],true)?$P['pass_policy']:'low';
  $qly=in_array($P['quality']??'',['low','mid','high'],true)?$P['quality']:'mid';
  $tz=$P['timezone']??'';
  if(!in_array($tz,timezone_identifiers_list(),true))$tz='Asia/Tehran';
  $dl=lang_code($P['default_lang']??'')?:'fa';
  $vals=['brand'=>mb_substr(trim($P['brand']??''),0,80),'domain'=>clean_domain($P['domain']??''),'stun'=>trim($P['stun']??''),'turn_url'=>trim($P['turn_url']??''),'turn_user'=>trim($P['turn_user']??''),'turn_pass'=>trim($P['turn_pass']??''),'turn_secret'=>trim($P['turn_secret']??''),'ice_relay'=>(string)(int)($P['ice_relay']??0),'quality'=>$qly,'link_mode'=>$mode,'lk_url'=>clean_lk($P['lk_url']??''),'lk_key'=>trim($P['lk_key']??''),'lk_secret'=>trim($P['lk_secret']??''),'calendar'=>$cal,'timezone'=>$tz,'pass_policy'=>$pol,'default_lang'=>$dl];
  foreach($vals as $k=>$v)q("REPLACE INTO settings VALUES(?,?)",[$k,$v]);
  out(['ok'=>1]);
 case 'set_icon':
  need_admin();
  if(empty($_FILES['f'])||$_FILES['f']['error'])fail(t('err.nofile'));
  if($_FILES['f']['size']>5*1048576)fail(t('err.filebig'));
  $im=@imagecreatefromstring(file_get_contents($_FILES['f']['tmp_name']));
  if(!$im)fail(t('err.badimage'));
  foreach([192,512] as $s){$o=imagecreatetruecolor($s,$s);imagecopyresampled($o,$im,0,0,0,0,$s,$s,imagesx($im),imagesy($im));imagepng($o,__DIR__."/assets/icon-$s.png");}
  out(['ok'=>1]);
 case 'join':
  $r=q("SELECT * FROM rooms WHERE code=?",[$P['code']??''])->fetch()?:fail(t('err.noroom'));
  $u=user();
  $pm=[0,0,0,0];
  $name=trim($P['name']??'');
  if(link_mode()==='sfu'&&(trim(setting('lk_url'))===''||trim(setting('lk_key'))===''||trim(setting('lk_secret'))===''))fail(t('err.sfu_missing'));
  if($u){
   $m=q("SELECT * FROM room_members WHERE room_id=? AND user_id=?",[$r['id'],$u['id']])->fetch();
   if($u['role']==='admin'||($m&&$m['is_manager']))$pm=[1,1,1,1];
   elseif($m)$pm=[$m['can_mic'],$m['can_cam'],$m['can_screen'],$m['can_board']];
   else fail(t('err.notmember'));
   $name=$u['name'];
  }else{
   if(!$r['guest_enabled'])fail(t('err.guestoff'));
   if($name==='')fail(t('err.needname'));
  }
  q("INSERT INTO participants(room_id,user_id,name,is_guest,can_mic,can_cam,can_screen,can_board,joined_at,last_seen) VALUES(?,?,?,?,?,?,?,?,NOW(),NOW())",array_merge([$r['id'],$u['id']??null,mb_substr($name,0,100),$u?0:1],$pm));
  $pid=(int)db()->lastInsertId();
  $_SESSION['p'][$pid]=1;
  $mode=link_mode();
  $lk=null;
  if($mode==='sfu'){
   $url=trim(setting('lk_url'));
   $tok=lk_token((string)$pid,$name,'r'.$r['id']);
   if($url===''||$tok==='')fail(t('err.sfu_missing'));
   $lk=['url'=>$url,'token'=>$tok];
  }
  out(['pid'=>$pid,'owner'=>$u?'u'.$u['id']:'g'.$pid,'ice'=>ice_servers(),'policy'=>setting('ice_relay')==='1'?'relay':'all','q'=>in_array(setting('quality'),['low','mid','high'],true)?setting('quality'):'mid','mode'=>$mode,'lk'=>$lk,'room'=>['id'=>$r['id'],'name'=>$r['name'],'link'=>base().'/room.php?c='.$r['code']],'sig'=>(int)q("SELECT IFNULL(MAX(id),0) FROM signals WHERE room_id=?",[$r['id']])->fetchColumn()]);
 case 'poll':
  $p=part();
  $rid=$p['room_id'];
  q("UPDATE participants SET last_seen=NOW(),left_at=NULL WHERE id=?",[$p['id']]);
  q("UPDATE participants SET left_at=last_seen WHERE room_id=? AND left_at IS NULL AND last_seen<NOW()-INTERVAL 15 SECOND",[$rid]);
  $parts=q("SELECT p.id,p.name,u.avatar av,p.mic_on,p.cam_on,p.screen_on,p.can_mic,p.can_cam,p.can_screen,p.can_board,p.is_guest,(u.role='admin' OR IFNULL(m.is_manager,0)=1) mgr FROM participants p LEFT JOIN users u ON u.id=p.user_id LEFT JOIN room_members m ON m.room_id=p.room_id AND m.user_id=p.user_id WHERE p.room_id=? AND p.left_at IS NULL ORDER BY p.id",[$rid])->fetchAll();
  $sigs=q("SELECT id,from_pid,type,data FROM signals WHERE room_id=? AND id>? AND from_pid<>? AND (to_pid=? OR to_pid=0) ORDER BY id LIMIT 200",[$rid,(int)$P['sig'],$p['id'],$p['id']])->fetchAll();
  if(mt_rand(1,30)==1)q("DELETE FROM signals WHERE created<NOW()-INTERVAL 3 MINUTE");
  $cs=(int)$P['chat'];
  $chat=$cs?q("SELECT id,name,text,owner FROM chat WHERE room_id=? AND deleted_at IS NULL AND id>? ORDER BY id",[$rid,$cs])->fetchAll():array_reverse(q("SELECT id,name,text,owner FROM chat WHERE room_id=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 50",[$rid])->fetchAll());
  out(['parts'=>$parts,'sigs'=>$sigs,'chat'=>$chat,'cdel'=>q("SELECT id FROM chat WHERE room_id=? AND deleted_at>NOW()-INTERVAL 30 SECOND",[$rid])->fetchAll(PDO::FETCH_COLUMN),'room'=>q("SELECT chat_enabled,rec_enabled FROM rooms WHERE id=?",[$rid])->fetch()]);
 case 'signal':
  $p=part();
  $type=$P['type']??'';
  if(!in_array($type,['offer','answer','ice','wbadd','wbundo','wbclear','wbreq','wbfull'],true))fail(t('err.badop'));
  if(in_array($type,['wbadd','wbundo','wbfull'],true)&&!($p['mgr']||$p['can_board']))fail(t('err.noboard'));
  if($type==='wbclear'&&!$p['mgr'])fail(t('err.boardclear'));
  if(strlen($P['data']??'')>900000)fail(t('err.toobig'));
  q("INSERT INTO signals(room_id,from_pid,to_pid,type,data) VALUES(?,?,?,?,?)",[$p['room_id'],$p['id'],(int)$P['to'],$type,$P['data']]);
  out(['ok'=>1]);
 case 'state':
  $p=part();
  $m=$p['mgr'];
  q("UPDATE participants SET mic_on=?,cam_on=?,screen_on=? WHERE id=?",[(int)($P['mic']&&($m||$p['can_mic'])),(int)($P['cam']&&($m||$p['can_cam'])),($m||$p['can_screen'])?((int)$P['scr']&7):0,$p['id']]);
  out(['ok'=>1]);
 case 'chat':
  $p=part();
  $on=q("SELECT chat_enabled FROM rooms WHERE id=?",[$p['room_id']])->fetchColumn();
  if(!$on&&!$p['mgr'])fail(t('err.chaton'));
  $text=trim(mb_substr($P['text']??'',0,500));
  if($text!=='')q("INSERT INTO chat(room_id,name,text,owner) VALUES(?,?,?,?)",[$p['room_id'],$p['name'],$text,$p['owner']]);
  out(['ok'=>1]);
 case 'chat_del':
  $p=part();
  $c=q("SELECT * FROM chat WHERE id=? AND room_id=?",[(int)$P['id'],$p['room_id']])->fetch()?:fail(t('err.nomsg'));
  if(!$p['mgr']&&$c['owner']!==$p['owner'])fail(t('err.chatown'));
  q("UPDATE chat SET deleted_at=NOW() WHERE id=?",[$c['id']]);
  out(['ok'=>1]);
 case 'chat_clear':
  $p=part();
  if(!$p['mgr'])fail(t('err.forbidden'));
  q("UPDATE chat SET deleted_at=NOW() WHERE room_id=? AND deleted_at IS NULL",[$p['room_id']]);
  out(['ok'=>1]);
 case 'room_flag':
  $p=part();
  if(!$p['mgr'])fail(t('err.forbidden'));
  q("UPDATE rooms SET chat_enabled=? WHERE id=?",[(int)$P['chat_enabled'],$p['room_id']]);
  out(['ok'=>1]);
 case 'perm':
  $p=part();
  if(!$p['mgr'])fail(t('err.forbidden'));
  $row=q("SELECT * FROM participants WHERE id=? AND room_id=?",[$P['target'],$p['room_id']])->fetch()?:fail(t('err.session'));
  $v=[(int)$P['can_mic'],(int)$P['can_cam'],(int)$P['can_screen'],(int)$P['can_board']];
  q("UPDATE participants SET can_mic=?,can_cam=?,can_screen=?,can_board=? WHERE id=?",array_merge($v,[$row['id']]));
  if($row['user_id'])q("UPDATE room_members SET can_mic=?,can_cam=?,can_screen=?,can_board=? WHERE room_id=? AND user_id=?",array_merge($v,[$row['room_id'],$row['user_id']]));
  out(['ok'=>1]);
 case 'leave':
  $p=part();
  q("UPDATE participants SET left_at=NOW(),mic_on=0,cam_on=0,screen_on=0 WHERE id=?",[$p['id']]);
  out(['ok'=>1]);
 case 'rec_start':
  $p=part();
  if(!$p['mgr'])fail(t('err.forbidden'));
  if(!q("SELECT rec_enabled FROM rooms WHERE id=?",[$p['room_id']])->fetchColumn())fail(t('err.norec'));
  $ext=($P['ext']??'')==='mp4'?'mp4':'webm';
  $fn='rec_'.$p['room_id'].'_'.time().'_'.bin2hex(random_bytes(8)).'.'.$ext;
  if(!touch(__DIR__.'/storage/'.$fn))fail(t('err.nosave'));
  q("INSERT INTO recordings(room_id,file) VALUES(?,?)",[$p['room_id'],$fn]);
  out(['id'=>(int)db()->lastInsertId()]);
 case 'rec_chunk':
  $p=part();
  if(!$p['mgr']||empty($_FILES['f']))fail(t('err.forbidden'));
  $r=q("SELECT * FROM recordings WHERE id=? AND room_id=?",[(int)$P['id'],$p['room_id']])->fetch()?:fail(t('err.nomsg'));
  $fn=basename($r['file']);
  if(!preg_match('/^rec_\d+_\d+_[a-f0-9]+\.(webm|mp4)$/',$fn))fail(t('err.forbidden'));
  $fp=__DIR__.'/storage/'.$fn;
  if(is_file($fp)&&filesize($fp)>2147483648)fail(t('err.toobig'));
  file_put_contents($fp,file_get_contents($_FILES['f']['tmp_name']),FILE_APPEND|LOCK_EX);
  clearstatcache();
  q("UPDATE recordings SET size=? WHERE id=?",[filesize($fp),$r['id']]);
  out(['ok'=>1]);
 case 'rec_list':
  if(!room_access((int)$P['room_id']))fail(t('err.forbidden'));
  out(['recs'=>q("SELECT id,size,created,file FROM recordings WHERE room_id=? ORDER BY id DESC",[$P['room_id']])->fetchAll(),'mgr'=>can_manage((int)$P['room_id'])]);
 case 'rec_del':
  $r=q("SELECT * FROM recordings WHERE id=?",[(int)$P['id']])->fetch();
  if(!$r||!can_manage((int)$r['room_id']))fail(t('err.forbidden'));
  @unlink(__DIR__.'/storage/'.basename($r['file']));
  q("DELETE FROM recordings WHERE id=?",[$r['id']]);
  out(['ok'=>1]);
 case 'rec_dl':
  $r=q("SELECT * FROM recordings WHERE id=?",[(int)$_GET['id']])->fetch();
  if(!$r||!room_access((int)$r['room_id']))die(t('err.forbidden'));
  $fn=basename($r['file']);
  if(!preg_match('/^rec_\d+_\d+_[a-f0-9]+\.(webm|mp4)$/',$fn))die(t('err.forbidden'));
  $fp=__DIR__.'/storage/'.$fn;
  if(!is_file($fp))die(t('err.nofile'));
  header('Content-Type: video/'.pathinfo($fp,PATHINFO_EXTENSION));
  header('Content-Disposition: attachment; filename="'.$fn.'"');
  header('Content-Length: '.filesize($fp));
  header('X-Content-Type-Options: nosniff');
  readfile($fp);
  exit;
 case 'attendance_list':
  $rid=(int)$P['room_id'];
  if(!can_manage($rid))fail(t('err.forbidden'));
  $w='room_id=?';$a2=[$rid];
  if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$P['from']??'')){$w.=' AND joined_at>=?';$a2[]=$P['from'].' 00:00:00';}
  if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$P['to']??'')){$w.=' AND joined_at<?';$a2[]=date('Y-m-d',strtotime($P['to'].' +1 day')).' 00:00:00';}
  out(['rows'=>q("SELECT name,is_guest,joined_at,IFNULL(left_at,last_seen) le,(left_at IS NULL AND last_seen>NOW()-INTERVAL 20 SECOND) online,TIMESTAMPDIFF(MINUTE,joined_at,IFNULL(left_at,last_seen)) mins FROM participants WHERE $w ORDER BY joined_at DESC LIMIT 5000",$a2)->fetchAll()]);
 case 'attendance':
  $rid=(int)$_GET['room_id'];
  if(!can_manage($rid))die(t('err.forbidden'));
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="attendance_'.$rid.'.csv"');
  echo "\xEF\xBB\xBF";
  $f=fopen('php://output','w');
  fputcsv($f,[t('att.name'),t('att.kind'),t('att.in'),t('att.out'),t('att.mins.n')]);
  foreach(q("SELECT name,is_guest,joined_at,IFNULL(left_at,last_seen) le,left_at FROM participants WHERE room_id=? ORDER BY joined_at",[$rid]) as $r)
   fputcsv($f,[$r['name'],$r['is_guest']?t('kind.guest'):t('kind.user'),dfmt($r['joined_at'],true),dfmt($r['le'],true).($r['left_at']?'':' ('.t('att.online').')'),round((strtotime($r['le'])-strtotime($r['joined_at']))/60,1)]);
  exit;
 default:fail(t('err.badop'));
 }
}catch(Throwable $e){
 log_err($e);
 http_response_code(500);
 out(['error'=>t('err.server')]);
}
