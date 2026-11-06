<?php
require 'config.php';
$code=preg_replace('/\W/','',$_GET['c']??'');
$r=q("SELECT * FROM rooms WHERE code=?",[$code])->fetch();
if(!$r){http_response_code(404);echo '<!doctype html><meta charset="utf-8"><title>'.h(t('room.missing')).'</title><p dir="'.h(html_dir()).'">'.h(t('room.missing')).'</p>';exit;}
$u=user();
$b=h(setting('brand')?:'دیدار');
$g=(int)$r['guest_enabled'];
$langs=lang_list();
$cur=html_lang();
$langOpt='';
foreach($langs as $id=>$m)$langOpt.='<option value="'.h($id).'"'.($id===$cur?' selected':'').'>'.h($m['name']??$id).'</option>';
$lk='';
if(link_mode()==='sfu'){
 $lkf=__DIR__.'/assets/livekit-client.umd.js';
 $lk=is_file($lkf)?asset('assets/livekit-client.umd.js'):'https://cdn.jsdelivr.net/npm/livekit-client@2.15.4/dist/livekit-client.umd.min.js';
}
?>
<!doctype html><html lang="<?=h(html_lang())?>" dir="<?=h(html_dir())?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover"><title><?=h($r['name'])?> - <?=$b?></title><link rel="manifest" href="manifest.php"><link rel="apple-touch-icon" href="assets/icon-192.png"><meta name="theme-color" content="#4f46e5"><link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="<?=asset('assets/app.css')?>"></head><body>
<div id="join" class="loginwrap"><div class="card login"><img src="assets/icon-192.png" alt=""><h2><?=h($r['name'])?></h2><p class="mu"><?=$b?></p>
<label><?=t('login.lang')?></label><select id="jlang"><?=$langOpt?></select>
<?php if($u):?><p id="jmsg" class="mu"><?=t('login.with',['name'=>h($u['name'])])?></p><p id="jerr" class="err na"></p><button class="btn block na" id="joinBtn" data-auto="1"><?=t('login.retry')?></button><p><a href="index.php"><?=t('login.back')?></a></p>
<?php else:?><?php if($g):?><div class="seg full" id="jt"><button class="act" data-p="acc"><?=t('login.account')?></button><button data-p="gst"><?=t('login.guest')?></button></div><?php endif;?>
<div id="p-acc"><label><?=t('login.user')?></label><input id="ju" autocomplete="username"><label><?=t('login.pass')?></label><input id="jp" type="password" autocomplete="current-password"><button class="btn block" id="jlogin"><?=t('login.submit')?></button></div>
<?php if($g):?><div id="p-gst" hidden><label><?=t('login.yourname')?></label><input id="gname"><p id="jerr" class="err na"></p><button class="btn block" id="joinBtn"><i data-i="enter"></i><?=t('login.asguest')?></button></div><?php endif;?><?php endif;?></div></div>
<div id="room" hidden><div class="shell"><header class="rtop"><h1><?=h($r['name'])?></h1><span class="badge gr"><span id="cnt">0</span> <?=t('room.online')?></span></header>
<div id="warn" class="warn na"><?=t('room.warn.https')?></div>
<div id="snd" class="warn na" style="cursor:pointer"><?=t('room.warn.sound')?></div>
<div class="rbody"><main class="stagewrap" id="stage"><div class="sth"><nav class="seg" id="vt"><button class="act" data-v="media"><i data-i="screen" data-s="16"></i><?=t('room.media')?></button><button data-v="board"><i data-i="board" data-s="16"></i><?=t('room.board')?></button></nav><span class="gx"></span><button class="btn ghost sm" id="sfs" data-i="max" data-s="16" title="<?=h(t('room.fs'))?>" style="width:36px;padding:0"></button></div>
<div class="stb" id="stb"><div id="media"><div id="scrs"></div><div id="tiles"></div><div id="empty" class="empty na"><div class="eic"><i data-i="users" data-s="28"></i></div><b id="et"></b><p class="mu" id="ed"></p></div></div>
<div id="board" class="na"><div class="tools" id="tools">
<div class="tgrp wt"><button class="wb on" data-tool="pen" title="<?=h(t('tool.pen'))?>" data-i="board" data-s="18"></button><button class="wb" data-tool="hl" title="<?=h(t('tool.hl'))?>" data-i="hl" data-s="18"></button><button class="wb" data-tool="er" title="<?=h(t('tool.er'))?>" data-i="er" data-s="18"></button><button class="wb" data-tool="line" title="<?=h(t('tool.line'))?>" data-i="line" data-s="18"></button><button class="wb" data-tool="arrow" title="<?=h(t('tool.arrow'))?>" data-i="arrow" data-s="18"></button><button class="wb" data-tool="rect" title="<?=h(t('tool.rect'))?>" data-i="rect" data-s="18"></button><button class="wb" data-tool="ell" title="<?=h(t('tool.ell'))?>" data-i="ell" data-s="18"></button><button class="wb" data-tool="text" title="<?=h(t('tool.text'))?>" data-i="text" data-s="18"></button></div>
<div class="tgrp wt"><button class="sat on" data-c="#111827" style="background:#111827"></button><button class="sat" data-c="#ef4444" style="background:#ef4444"></button><button class="sat" data-c="#f59e0b" style="background:#f59e0b"></button><button class="sat" data-c="#16a34a" style="background:#16a34a"></button><button class="sat" data-c="#2563eb" style="background:#2563eb"></button><button class="sat" data-c="#7c3aed" style="background:#7c3aed"></button><input type="color" id="wc" value="#111827" title="<?=h(t('tool.color'))?>"></div>
<div class="tgrp wt"><button class="szb" data-sz="3" title="<?=h(t('tool.thin'))?>"><i style="width:4px;height:4px"></i></button><button class="szb on" data-sz="6" title="<?=h(t('tool.mid'))?>"><i style="width:8px;height:8px"></i></button><button class="szb" data-sz="12" title="<?=h(t('tool.thick'))?>"><i style="width:13px;height:13px"></i></button></div>
<div class="tgrp"><button class="wb wt" id="wundo" title="<?=h(t('tool.undo'))?>" data-i="undo" data-s="18"></button><button class="wb on" id="wgrid" title="<?=h(t('tool.grid'))?>" data-i="grid" data-s="18"></button><button class="wb" id="wfs" title="<?=h(t('room.fs'))?>" data-i="max" data-s="18"></button><button class="wb" id="wsave" title="<?=h(t('tool.save'))?>" data-i="down" data-s="18"></button><button class="wb mgo na" id="wclr" title="<?=h(t('tool.clear'))?>" data-i="trash" data-s="18"></button><span class="badge ronly"><i data-i="eye" data-s="12" style="display:inline-block;vertical-align:middle"></i> <?=t('room.view')?></span></div></div>
<div class="cvw"><canvas id="cv" width="1600" height="900"></canvas></div></div></div></main>
<aside class="side" id="side"><div class="sh"><nav class="seg"><button class="act" data-p="Chat"><i data-i="chat" data-s="16"></i><?=t('room.chat')?></button><button data-p="Users"><i data-i="users" data-s="16"></i><?=t('room.people')?></button></nav>
<button class="btn ghost sm" id="smin" data-i="min" data-s="16" style="width:34px;padding:0"></button><button class="btn ghost sm" id="smax" data-i="max" data-s="16" style="width:34px;padding:0"></button></div>
<div class="sb"><div class="pane" id="paneChat"><div class="cbar mgo na"><label class="toggle"><span class="sw"><input type="checkbox" id="chatSw"><i></i></span><?=t('room.chaton')?></label><button class="btn danger sm" id="cclr"><i data-i="trash" data-s="14"></i><?=t('room.chatclear')?></button></div><div id="msgs"></div><form id="cf"><input id="ct" placeholder="<?=h(t('room.msg'))?>" autocomplete="off"><button class="btn" data-i="send" data-s="18"></button></form></div><div class="pane na" id="paneUsers"><div id="users"></div></div></div></aside></div>
<div id="recbar" class="recbar na"><span class="recfill"></span><span id="recpct"></span></div><nav class="bar"><button class="rb" id="bMic"><i data-i="micoff" data-s="22"></i><small><?=t('room.mic')?></small></button><button class="rb" id="bCam"><i data-i="camoff" data-s="22"></i><small><?=t('room.cam')?></small></button><button class="rb" id="bScr"><i data-i="screen" data-s="22"></i><small><?=t('room.screen')?></small></button><button class="rb on" id="bSpk"><i data-i="spk" data-s="22"></i><small><?=t('room.spk')?></small></button><button class="rb na" id="bRec"><i data-i="rec" data-s="22"></i><small><?=t('room.rec')?></small></button><button class="rb" id="bLink"><i data-i="link" data-s="22"></i><small><?=t('room.link')?></small></button><button class="rb red" id="bLeave"><i data-i="leave" data-s="22"></i><small><?=t('room.leave')?></small></button></nav></div></div>
<script>window.R={code:"<?=h($code)?>"};</script>
<?=i18n_boot_js()?>
<script src="<?=asset('assets/jquery.min.js')?>"></script><script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<?php if($lk):?><script src="<?=h($lk)?>"></script><?php endif;?>
<script src="<?=asset('assets/common.js')?>"></script><script src="<?=asset('assets/room.js')?>"></script><script>if('serviceWorker'in navigator)navigator.serviceWorker.register('sw.js')</script></body></html>
