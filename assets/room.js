$(function(){const R=window.R,SL=5,W=1600,H=900;
let S={},pcs={},L={mic:null,cam:null,camS:null,scr:[null,null,null],scrS:[null,null,null]},sSig=0,sChat=0,parts=[],spk=true,rec=null,chain=Promise.resolve(),uh='',shown=0,view='media',prevScr='',openU={},lk=null,sfuP=null,warned=0,leaving=0;
const api=(a,d)=>$.ajax({url:'api.php?action='+a,method:'POST',data:Object.assign({pid:S.pid},d),dataType:'json'}).fail(x=>toast((x.responseJSON||{}).error||t('err.net')));
const sig=(to,type,data)=>api('signal',{to,type,data:JSON.stringify(data)});
const seti=(sel,n,s=22)=>$(sel).find('i').html(ic(n,s));
if(!window.isSecureContext)$('#warn').removeClass('na');
$('#jt button').click(function(){$('#jt button').removeClass('act');$(this).addClass('act');$('#p-acc,#p-gst').hide();$('#p-'+$(this).data('p')).show()});
$('#jlang').change(function(){$.post('api.php?action=lang_pick',{lang:this.value},null,'json').done(()=>location.reload())});
const doLogin=()=>$.post('api.php?action=login',{username:$('#ju').val(),password:$('#jp').val(),lang:$('#jlang').val()},null,'json').done(r=>{if(r.csrf)window.CSRF=r.csrf;location.reload()}).fail(x=>toast((x.responseJSON||{}).error||t('err.net')));
$('#jlogin').click(doLogin);$('#ju,#jp').keydown(e=>{if(e.key=='Enter')doLogin()});
function doJoin(){$('#joinBtn').addClass('na');$('#jerr').addClass('na');
 $.ajax({url:'api.php?action=join',method:'POST',dataType:'json',data:{code:R.code,name:$('#gname').val()||''}}).done(r=>{S=r;sSig=r.sig;if(S.mode==='sfu')sfuP=sfuStart();loop()})
 .fail(x=>{$('#jmsg').addClass('na');$('#jerr').text((x.responseJSON||{}).error||t('err.generic')).removeClass('na');$('#joinBtn').removeClass('na')})}
$('#joinBtn').click(doJoin);if($('#joinBtn').data('auto'))doJoin();
function loop(){api('poll',{sig:sSig,chat:sChat}).done(handle).always(()=>setTimeout(loop,S.mode==='sfu'?1200:800))}
function handle(r){parts=r.parts;S.me=parts.find(p=>p.id==S.pid)||S.me||{};if(Date.now()<(S.lock||0)&&S.me.id==S.pid){S.me.mic_on=L.mic?1:0;S.me.cam_on=L.cam?1:0;S.me.screen_on=mask()}S.chatOn=+r.room.chat_enabled;S.recOn=+r.room.rec_enabled;const mg=+S.me.mgr?1:0;
 r.sigs.forEach(s=>{sSig=Math.max(sSig,+s.id);chain=chain.then(()=>onSig(s)).catch(console.error)});
 r.cdel.forEach(id=>$('#m'+id).remove());
 r.chat.forEach(c=>{sChat=Math.max(sChat,+c.id);$('#msgs').append(`<div class="m${c.owner==S.owner?' mine':''}" id="m${c.id}"><b>${esc(c.name)}</b>${esc(c.text)}${(mg||c.owner==S.owner)?`<button class="dl" data-id="${c.id}" title="${t('del.msg')}">${ic('trash',12)}</button>`:''}</div>`)});
 if(r.chat.length)$('#msgs').scrollTop(1e9);
 if(S.mode==='sfu')sfuSync();
 else{parts.forEach(p=>{if(p.id!=S.pid&&!pcs[p.id]&&S.pid>p.id)connect(p.id)});Object.keys(pcs).forEach(id=>{if(!parts.some(p=>p.id==id))drop(id)});}
 if(S.mode!=='sfu'&&parts.length>8&&!warned){warned=1;toast(t('mode.p2p.many'))}
 const can=k=>mg||+S.me['can_'+k];if(!can('mic')&&L.mic)stopMic();if(!can('cam')&&L.cam)stopCam();if(!can('screen'))L.scr.forEach((tr,k)=>tr&&stopScr(k));
 ui();if(!shown){shown=1;$('#join').hide();$('#room').show();sig(0,'wbreq',1)}}
function sfuStart(){
 if(!window.LivekitClient){toast(t('err.sfu.sdk'));return Promise.resolve()}
 const C=LivekitClient;
 lk=new C.Room({adaptiveStream:true,dynacast:true});
 lk.on(C.RoomEvent.TrackPublished,(pub)=>{try{pub.setSubscribed(true)}catch(e){}});
 lk.on(C.RoomEvent.TrackSubscribed,(track,pub,p)=>{sfuAttach(p.identity,pub&&pub.source,track)});
 lk.on(C.RoomEvent.TrackUnsubscribed,(track,pub,p)=>{sfuDetach(p.identity,pub&&pub.source,track)});
 lk.on(C.RoomEvent.LocalTrackUnpublished,(pub)=>{if(pub.source===C.Track.Source.ScreenShare){L.scr[0]=null;L.scrS[0]=null;$('#s'+S.pid+'-0').remove();state()}});
 lk.on(C.RoomEvent.Disconnected,()=>{if(!leaving)toast(t('err.sfu'))});
 if(C.RoomEvent.AudioPlaybackStatusChanged)lk.on(C.RoomEvent.AudioPlaybackStatusChanged,()=>{if(lk.canPlaybackAudio===false){blocked=true;$('#snd').removeClass('na')}});
 return lk.connect(S.lk.url,S.lk.token).then(()=>{sfuSweep();if(lk.startAudio)lk.startAudio().catch(()=>{blocked=true;$('#snd').removeClass('na')});uh='';ui()}).catch(()=>{if(!leaving)toast(t('err.sfu'))});
}
function sfuSlot(source){const Src=LivekitClient.Track.Source;if(source===Src.Microphone||source==='microphone')return 'mic';if(source===Src.Camera||source==='camera')return 'cam';if(source===Src.ScreenShare||source==='screen_share')return 'scr';return ''}
function sfuBox(id){return pcs[id]||(pcs[id]={pc:{close(){}},rs:[],snd:[],q:[],au:null,st:'connected',ct:'sfu'})}
function sfuPlay(el){if(el&&el.play)el.play().catch(()=>{blocked=true;$('#snd').removeClass('na')})}
function sfuAttach(id,source,track){const slot=sfuSlot(source);if(!slot||!track)return;const o=sfuBox(id);const mst=track.mediaStreamTrack?new MediaStream([track.mediaStreamTrack]):null;if(slot==='mic'){if(o.auT&&o.auT!==track){try{o.auT.detach()}catch(e){}}o.auT=track;if(o.au){try{o.au.remove()}catch(e){}}o.au=track.attach();o.au.autoplay=true;o.au.muted=!spk;document.body.appendChild(o.au);o.rs[0]=mst;if(mst)mixAdd(mst);sfuPlay(o.au)}else if(slot==='cam'){o.vcam=track;o.rs[1]=mst}else{o.vscr=track;o.rs[2]=mst}uh='';ui()}
function sfuDetach(id,source,track){const slot=sfuSlot(source);if(!slot)return;const o=pcs[id];if(!o)return;if(slot==='mic'){if(o.au){try{if(track&&track.detach)track.detach(o.au)}catch(e){}o.au.remove();o.au=null}o.auT=null;o.rs[0]=null}else if(slot==='cam'){o.vcam=null;o.rs[1]=null}else{o.vscr=null;o.rs[2]=null}uh='';ui()}
function sfuSweep(){if(!lk||!lk.remoteParticipants)return;lk.remoteParticipants.forEach(p=>{if(!p.trackPublications)return;p.trackPublications.forEach(pub=>{if(pub.track)sfuAttach(p.identity,pub.source,pub.track);else try{pub.setSubscribed(true)}catch(e){}})})}
function sfuSync(){parts.forEach(p=>{if(p.id!=S.pid&&lk&&lk.remoteParticipants&&lk.remoteParticipants.has(String(p.id)))sfuBox(p.id)});Object.keys(pcs).forEach(id=>{if(!parts.some(p=>p.id==id)){const o=pcs[id];if(o&&o.au){try{o.au.remove()}catch(e){}}if(o&&o.pc&&o.pc.close)try{o.pc.close()}catch(e){}delete pcs[id]}})}
async function sfuReady(){if(sfuP)await sfuP;if(!lk)throw new Error('lk')}
function peer(id,init){const pc=new RTCPeerConnection({iceServers:S.ice,iceTransportPolicy:S.policy||'all'}),o={pc,rs:[],snd:[],q:[],au:null};pcs[id]=o;
 if(init){o.snd=[pc.addTransceiver('audio').sender];for(let i=1;i<SL;i++)o.snd.push(pc.addTransceiver('video').sender);useLocal(o)}
 pc.ontrack=e=>{const i=pc.getTransceivers().indexOf(e.transceiver);if(i<0)return;o.rs[i]=new MediaStream([e.track]);const kick=()=>{uh='';if(i===0)attachAudio(id);ui()};e.track.onunmute=kick;e.track.onmute=()=>{uh='';ui()};kick()};
 pc.onicecandidate=e=>e.candidate&&sig(id,'ice',e.candidate);
 pc.oniceconnectionstatechange=()=>{o.st=pc.iceConnectionState;uh='';if(['connected','completed'].includes(o.st))pc.getStats().then(rp=>{rp.forEach(x=>{if(x.type=='candidate-pair'&&x.nominated&&x.state=='succeeded'){const l=rp.get(x.localCandidateId);o.ct=l&&l.candidateType;uh=''}})}).catch(()=>{});if(o.st=='failed')toast(t('room.novideo'))};
 pc.onconnectionstatechange=()=>{if(['failed','closed'].includes(pc.connectionState)&&pcs[id]===o)drop(id)};return o}
async function useLocal(o){const tracks=[L.mic,L.cam,...L.scr];let changed=false;for(let i=0;i<o.snd.length;i++){const s=o.snd[i];if(!s)continue;const nt=tracks[i]||null;if(s.track===nt)continue;changed=true;try{await s.replaceTrack(nt)}catch(e){}}return changed}
const useAll=()=>Object.entries(pcs).forEach(([id,o])=>{Promise.resolve(useLocal(o)).then(ch=>{if(ch)sendOffer(id)})});
function sendOffer(id){const o=pcs[id];if(!o||S.mode==='sfu')return;o.oq=(o.oq||Promise.resolve()).then(async()=>{for(let n=0;n<20&&pcs[id]&&o.pc.signalingState!=='stable';n++)await new Promise(r=>setTimeout(r,50));if(!pcs[id]||o.pc.signalingState!=='stable')return;const d=await o.pc.createOffer();if(o.pc.signalingState!=='stable')return;await o.pc.setLocalDescription(d);await sig(id,'offer',o.pc.localDescription)}).catch(()=>{})}
function drop(id){const o=pcs[id];if(!o)return;try{o.pc.close()}catch(e){}o.au&&o.au.remove();delete pcs[id]}
function connect(id){peer(id,true);sendOffer(id)}
function attachAudio(id){const o=pcs[id];if(!o||!o.rs[0])return;if(!o.au){o.au=$('<audio autoplay playsinline>').appendTo('body')[0];mixAdd(o.rs[0])}if(o.au.srcObject!==o.rs[0])o.au.srcObject=o.rs[0];o.au.muted=!spk;o.au.play&&o.au.play().catch(()=>{blocked=true;$('#snd').removeClass('na')})}
let blocked=false;
const unlock=()=>{Object.values(pcs).forEach(o=>o.au&&o.au.play&&o.au.play().catch(()=>{}));if(lk&&lk.startAudio)lk.startAudio().catch(()=>{});blocked=false;$('#snd').addClass('na')};
$(document).on('pointerdown keydown touchstart',()=>{if(blocked)unlock()});$('#snd').click(unlock);
async function onSig(s){const d=JSON.parse(s.data),id=s.from_pid;
 if(s.type=='wbadd'){objs.push(d);redraw();setView('board');return}
 if(s.type=='wbundo'){objs=objs.filter(o=>o.id!=d.id);redraw();return}
 if(s.type=='wbclear'){objs=[];redraw();return}
 if(s.type=='wbreq'){if(objs.length&&canB())sig(id,'wbfull',{o:objs});return}
 if(s.type=='wbfull'){if(!gotFull){gotFull=1;objs=d.o;redraw()}return}
 if(S.mode==='sfu')return;
 if(s.type=='offer'){let o=pcs[id];if(!o)o=peer(id,false);const polite=+S.pid<+id;if(o.pc.signalingState!=='stable'){if(!polite)return;try{await o.pc.setLocalDescription({type:'rollback'})}catch(e){}}if(o.pc.signalingState!=='stable')return;await o.pc.setRemoteDescription(d);o.pc.getTransceivers().forEach((tr,i)=>{tr.direction='sendrecv';o.snd[i]=tr.sender});await useLocal(o);await o.pc.setLocalDescription(await o.pc.createAnswer());sig(id,'answer',o.pc.localDescription);o.q.splice(0).forEach(c=>o.pc.addIceCandidate(c).catch(()=>{}))}
 else{const o=pcs[id];if(!o)return;if(s.type=='answer'){await o.pc.setRemoteDescription(d);o.q.forEach(c=>o.pc.addIceCandidate(c));o.q=[]}
  else if(s.type=='ice'){o.pc.remoteDescription?o.pc.addIceCandidate(d).catch(()=>{}):o.q.push(d)}}}
function vid(el,st){const v=$('<video autoplay playsinline muted>').prop({srcObject:st,muted:true}).appendTo(el)[0];v.onplaying=()=>el.closest('.tile').find('.wait').remove();v.play&&v.play().catch(()=>{})}
function putLk(el,track){if(!el.length||!track||!track.attach)return;if(el.data('lk')===track&&el.find('video').length){const v=el.find('video')[0];if(v.paused&&v.play)v.play().catch(()=>{});return}const old=el.data('lk');if(old&&old.detach){try{old.detach()}catch(e){}}el.empty();const v=track.attach();v.autoplay=true;v.playsInline=true;v.muted=true;el.append(v);el.data('lk',track);v.play&&v.play().catch(()=>{});el.closest('.tile').find('.wait').remove()}
function mediaOn(p){const o=pcs[p.id]||{};if(p.id==S.pid)return{cam:L.cam?1:0,scr:mask()};let scr=+p.screen_on||0;if(S.mode==='sfu'){if(o.vscr)scr|=1;return{cam:(+p.cam_on||o.vcam)?1:0,scr}}const up=i=>{const st=o.rs&&o.rs[i];const tr=st&&st.getVideoTracks&&st.getVideoTracks()[0];return !!(tr&&tr.readyState!=='ended'&&!tr.muted)};for(let k=0;k<3;k++)if(up(k+2))scr|=1<<k;return{cam:(+p.cam_on||up(1))?1:0,scr}}
function refreshVids(){Object.entries(pcs).forEach(([id,o])=>o.rs.forEach((st,i)=>{if(!st||i<1)return;const el=i==1?$('#t'+id+'.camon .vw'):$('#s'+id+'-'+(i-2)+' .vw');if(el.length&&!el.find('video').length)vid(el,st)}))}
function fs(el){const d=document;if(d.fullscreenElement||d.webkitFullscreenElement){(d.exitFullscreen||d.webkitExitFullscreen).call(d);return}
 if(el.classList.contains('fsx')){el.classList.remove('fsx');return}const f=el.requestFullscreen||el.webkitRequestFullscreen;
 if(f){try{const p=f.call(el);p&&p.catch&&p.catch(()=>el.classList.add('fsx'))}catch(e){el.classList.add('fsx')}}else el.classList.add('fsx')}
$(document).on('click','[data-fs]',function(){fs($(this).closest('.tile')[0])});$('#sfs').click(()=>fs($('#stage')[0]));$('#wfs').click(()=>fs($('#board')[0]));
['fullscreenchange','webkitfullscreenchange'].forEach(e=>document.addEventListener(e,()=>{const f=document.fullscreenElement||document.webkitFullscreenElement;$('#sfs').html(ic(f==$('#stage')[0]?'min2':'max',16));$('#wfs').html(ic(f==$('#board')[0]?'min2':'max',18))}));
function setView(v){view=v;$('#vt button').removeClass('act').filter('[data-v='+v+']').addClass('act').removeClass('dot');const hold=!!rec;$('#media').toggleClass('na',v!='media'&&!hold).toggleClass('offstage',v!='media'&&hold);$('#board').toggleClass('na',v!='board'&&!hold).toggleClass('offstage',v!='board'&&hold);$('#stb').toggleClass('bv',v=='board')}
$('#vt button').click(function(){setView($(this).data('v'))});
const PM=[['mic','mic','perm.mic'],['cam','cam','perm.cam'],['screen','screen','perm.screen'],['board','board','perm.board']];
function ui(){const mg=+S.me.mgr?1:0,seen={},dnm={};parts.forEach(p=>{seen[p.name]=(seen[p.name]||0)+1;dnm[p.id]=seen[p.name]>1?p.name+' ('+locn(seen[p.name])+')':p.name});$('#cnt').text(locn(parts.length));
 const vis=parts.filter(p=>+p.mgr||+p.can_mic||+p.can_cam||+p.can_screen);
 vis.forEach(p=>{let el=$('#t'+p.id);if(!el.length)el=$(`<div class="tile" id="t${p.id}"><div class="vw"></div><div class="av" ${p.av?`style="background-image:url(assets/avatars/${encodeURIComponent(p.av)});color:transparent"`:''}>${esc((p.name||'?')[0])}</div><div class="nm"><span class="mi"></span><span></span></div><div class="tact"><button class="tb" data-fs="1">${ic('max',16)}</button></div></div>`).appendTo('#tiles');
  const mo=mediaOn(p),on=(+p.mic_on||(p.id==S.pid&&L.mic)||(S.mode==='sfu'&&pcs[p.id]&&pcs[p.id].auT))?1:0,cm=mo.cam;el.toggleClass('camon',!!cm).toggleClass('mc',!!on);
  if(el.data('m')!==on){el.data('m',on);el.find('.mi').html(`<span class="${on?'on':'off'}">${ic(on?'mic':'micoff',14)}</span>`)}
  if(!cm){const box=el.find('.vw');const old=box.data('lk');if(old&&old.detach){try{old.detach()}catch(e){}}box.removeData('lk');el.find('video').remove()}
  else if(p.id==S.pid&&L.camS&&!el.find('video').length)vid(el.find('.vw'),L.camS);
  else if(p.id!=S.pid&&pcs[p.id]&&pcs[p.id].vcam)putLk(el.find('.vw'),pcs[p.id].vcam);
  else if(p.id!=S.pid&&pcs[p.id]&&pcs[p.id].rs[1]&&!el.find('video').length)vid(el.find('.vw'),pcs[p.id].rs[1]);
  el.find('.nm span:last').text(dnm[p.id]+(p.id==S.pid?' ('+t('room.you')+')':''))});
 $('#tiles .tile').each(function(){if(!vis.some(p=>'t'+p.id==this.id))$(this).remove()});
 const keys=[];parts.forEach(p=>{const mo=mediaOn(p),o=pcs[p.id]||{};for(let k=0;k<3;k++){const id='s'+p.id+'-'+k;let el=$('#'+id);
  if((mo.scr>>k)&1){keys.push(id);if(!el.length)el=$(`<div class="tile scr" id="${id}"><div class="vw"></div><div class="wait">${t('room.receiving')}</div><div class="nm"><span>${ic('screen',14)}</span><span></span></div><div class="tact"><button class="tb" data-fs="1" title="${t('room.fs')}">${ic('max',16)}</button></div></div>`).appendTo('#scrs');
   el.find('.nm span:last').text(dnm[p.id]+' · '+t('room.desktop'));
   if(p.id==S.pid&&L.scrS[k]&&!el.find('video').length)vid(el.find('.vw'),L.scrS[k]);
   else if(p.id!=S.pid&&k===0&&o.vscr)putLk(el.find('.vw'),o.vscr);
   else if(p.id!=S.pid&&o.rs&&o.rs[k+2]&&!el.find('video').length)vid(el.find('.vw'),o.rs[k+2])}
  else if(el.length){const old=el.find('.vw').data('lk');if(old&&old.detach){try{old.detach()}catch(e){}}el.remove()}}});
 const ks=keys.join();if(ks!==prevScr){if(keys.some(k=>!prevScr.split(',').includes(k)))setView('media');prevScr=ks}
 $('#media').toggleClass('hasscr',keys.length>0);
 $('#scrs .wait').each(function(){const el=$(this).closest('.tile');if(!el.data('w'))el.data('w',Date.now());else if(Date.now()-el.data('w')>10000)$(this).text(t('room.novideo'))});
 refreshVids();$('#empty').toggleClass('na',vis.length>0||keys.length>0);
 const none=!(mg||+S.me.can_mic||+S.me.can_cam||+S.me.can_screen||+S.me.can_board);
 $('#et').text(none?t('room.wait'):t('room.none'));$('#ed').text(none?t('room.waitd'):t('room.noned'));
 $('#bMic').toggleClass('lock',!(mg||+S.me.can_mic));$('#bCam').toggleClass('lock',!(mg||+S.me.can_cam));$('#bScr').toggleClass('lock',!(mg||+S.me.can_screen));
 $('#bScr').toggleClass('on',!!L.scr[0]).find('small').text(L.scr[0]?t('room.screen.stop'):t('room.screen'));
 $('#bRec').toggleClass('na',!(mg&&S.recOn));$('.mgo').toggleClass('na',!mg);$('#chatSw').prop('checked',!!S.chatOn);
 $('#ct,#cf .btn').prop('disabled',!S.chatOn&&!mg);$('#ct').attr('placeholder',S.chatOn||mg?t('room.msg'):t('room.msgoff'));
 $('#tools').toggleClass('ro',!canB());
 const h=JSON.stringify(parts.map(p=>[p.id,p.can_mic,p.can_cam,p.can_screen,p.can_board,p.mic_on,p.mgr,mg,(pcs[p.id]||{}).st,(pcs[p.id]||{}).ct]))+JSON.stringify(openU);if(h==uh)return;uh=h;
 $('#users').html(parts.map(p=>{const ed=mg&&p.id!=S.pid&&!+p.mgr;const ct=({host:t('net.lan'),srflx:t('net.direct'),prflx:t('net.direct'),relay:t('net.turn'),sfu:t('net.sfu')})[(pcs[p.id]||{}).ct]||'';return `<div class="u ${ed?'ed':''} ${openU[p.id]?'open':''}" data-id="${p.id}"><div class="uh">${avh({name:p.name,avatar:p.av},34)}<div class="gx"><b>${esc(dnm[p.id])}</b> ${+p.mgr?'<span class="badge pr">'+t('room.mgr')+'</span>':+p.is_guest?'<span class="badge">'+t('kind.guest')+'</span>':''}</div>${p.id!=S.pid&&pcs[p.id]?`<span class="st ${pcs[p.id].st||'new'}" title="${t('net.title')}"></span><span class="mu">${ct}</span>`:''}${ed?`<span class="chev">${ic('chev',16)}</span>`:''}</div>
  ${ed?`<div class="up">${PM.map(([k,i,key])=>`<label class="pm"><span>${ic(i,16)}${t(key)}</span><span class="sw"><input type="checkbox" data-k="${k}" ${+p['can_'+k]?'checked':''}><i></i></span></label>`).join('')}</div>`:''}</div>`}).join(''))}
$('#users').on('click','.u.ed .uh',function(){const id=$(this).closest('.u').data('id');openU[id]=!openU[id];uh='';ui()});
$('#users').on('change','input',function(){const r=$(this).closest('.u'),g=k=>+r.find(`[data-k=${k}]`).prop('checked');api('perm',{target:r.data('id'),can_mic:g('mic'),can_cam:g('cam'),can_screen:g('screen'),can_board:g('board')})});
$('#chatSw').change(function(){api('room_flag',{chat_enabled:this.checked?1:0})});
$('#cf').submit(e=>{e.preventDefault();const t=$('#ct').val();if(t){$('#ct').val('');api('chat',{text:t})}});
$('#msgs').on('click','.dl',function(){const id=$(this).data('id');api('chat_del',{id}).done(()=>$('#m'+id).remove())});
$('#cclr').click(()=>confirm(t('confirm.chat'))&&api('chat_clear').done(()=>$('#msgs').empty()));
$('.sh .seg button').click(function(){$('.sh .seg button').removeClass('act');$(this).addClass('act');$('#paneChat,#paneUsers').addClass('na');$('#pane'+$(this).data('p')).removeClass('na')});
$('#smin').click(()=>$('#side').removeClass('fsx').toggleClass('mini'));$('#smax').click(()=>$('#side').removeClass('mini').toggleClass('fsx'));
const QP=()=>({low:{fps:8,br:600000,w:1280,h:720},mid:{fps:12,br:1200000,w:1920,h:1080},high:{fps:20,br:2500000,w:1920,h:1080}})[S.q||'mid'];
const mask=()=>L.scr.reduce((a,t,k)=>a|(t?1<<k:0),0);
const state=()=>{const me=parts.find(p=>p.id==S.pid);S.lock=Date.now()+4000;if(me){me.mic_on=L.mic?1:0;me.cam_on=L.cam?1:0;me.screen_on=mask();uh='';ui()}setTimeout(()=>Object.values(pcs).forEach(tune),700);return api('state',{mic:L.mic?1:0,cam:L.cam?1:0,scr:mask()})};
function tune(o){o.snd.forEach((s,i)=>{if(i<1||!s||!s.track)return;try{const p=s.getParameters();if(!p.encodings||!p.encodings.length)p.encodings=[{}];p.encodings[0].maxBitrate=i==1?400000:QP().br;s.setParameters(p).catch(()=>{})}catch(e){}})}
const noMedia=()=>{if(!navigator.mediaDevices){toast(t('err.https'));return true}};
async function startMic(){if(noMedia())return;try{if(S.mode==='sfu'){await sfuReady();const pub=await lk.localParticipant.setMicrophoneEnabled(true);L.mic=pub&&pub.track?pub.track.mediaStreamTrack:null;if(L.mic)mixAdd(new MediaStream([L.mic]))}else{const st=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true}});L.mic=st.getAudioTracks()[0];mixAdd(st);useAll()}$('#bMic').addClass('on');seti('#bMic','mic');state()}catch(e){toast(t('err.mic'))}}
function stopMic(){if(S.mode==='sfu'&&lk)lk.localParticipant.setMicrophoneEnabled(false).catch(()=>{});else L.mic&&L.mic.stop();L.mic=null;if(S.mode!=='sfu')useAll();$('#bMic').removeClass('on');seti('#bMic','micoff');state()}
$('#bMic').click(()=>{if(!(+S.me.mgr||+S.me.can_mic))return toast(t('err.nomic'));L.mic?stopMic():startMic()});
async function startCam(){if(noMedia())return;try{if(S.mode==='sfu'){await sfuReady();const pub=await lk.localParticipant.setCameraEnabled(true);const tr=pub&&pub.track?pub.track.mediaStreamTrack:null;L.cam=tr;L.camS=tr?new MediaStream([tr]):null}else{const st=await navigator.mediaDevices.getUserMedia({video:{width:{ideal:640},height:{ideal:360},frameRate:{ideal:24}}});L.camS=st;L.cam=st.getVideoTracks()[0];L.cam.onended=stopCam;useAll()}$('#bCam').addClass('on');seti('#bCam','cam');state().always(ui)}catch(e){toast(t('err.cam'))}}
function stopCam(){if(S.mode==='sfu'&&lk)lk.localParticipant.setCameraEnabled(false).catch(()=>{});else L.cam&&L.cam.stop();L.cam=L.camS=null;if(S.mode!=='sfu')useAll();$('#bCam').removeClass('on');seti('#bCam','camoff');$('#t'+S.pid+' video').remove();state()}
$('#bCam').click(()=>{if(!(+S.me.mgr||+S.me.can_cam))return toast(t('err.nocam'));L.cam?stopCam():startCam()});
async function addScr(){if(noMedia())return;if(S.mode!=='sfu'&&!navigator.mediaDevices.getDisplayMedia)return toast(t('err.scrdevice'));const k=0;try{if(S.mode==='sfu'){await sfuReady();const q=QP();const pub=await lk.localParticipant.setScreenShareEnabled(true,{audio:false,contentHint:'detail',resolution:{width:q.w,height:q.h,frameRate:q.fps}});const tr=pub&&pub.track?pub.track.mediaStreamTrack:null;L.scr[k]=tr;L.scrS[k]=tr?new MediaStream([tr]):null;if(tr)tr.onended=()=>stopScr(k)}else{const st=await navigator.mediaDevices.getDisplayMedia({video:{frameRate:{ideal:QP().fps,max:30},width:{max:QP().w},height:{max:QP().h}}});L.scrS[k]=st;L.scr[k]=st.getVideoTracks()[0];L.scr[k].onended=()=>stopScr(k);useAll()}state().always(ui)}catch(e){toast(t('err.scr'))}}
function stopScr(k){const tr=L.scr[k];if(S.mode==='sfu'){if(tr)tr.onended=null;L.scr[k]=L.scrS[k]=null;$('#s'+S.pid+'-'+k).remove();if(lk)lk.localParticipant.setScreenShareEnabled(false).catch(()=>{});state();return}if(!tr)return;tr.onended=null;tr.stop();L.scr[k]=L.scrS[k]=null;useAll();$('#s'+S.pid+'-'+k).remove();state()}
$('#bScr').click(()=>{if(L.scr[0])return stopScr(0);if(!(+S.me.mgr||+S.me.can_screen))return toast(t('err.noscr'));addScr()});
$('#bSpk').click(function(){spk=!spk;Object.values(pcs).forEach(o=>o.au&&(o.au.muted=!spk));$(this).toggleClass('on',spk);seti(this,spk?'spk':'spkoff')});
$('#bLink').click(()=>linkModal(S.room.link));
$('#bLeave').click(()=>{if(rec)return toast(t('rec.block'));leaving=1;if(lk)try{lk.disconnect()}catch(e){}Object.keys(pcs).forEach(drop);L.mic&&L.mic.stop();L.cam&&L.cam.stop();L.scr.forEach(tr=>tr&&tr.stop());api('leave').always(()=>location.href='index.php')});
addEventListener('beforeunload',e=>{if(!rec)return;e.preventDefault();e.returnValue=''});
addEventListener('pagehide',()=>{if(rec||!S.pid||!navigator.sendBeacon)return;const f=new FormData();f.append('pid',S.pid);if(window.CSRF)f.append('csrf',window.CSRF);navigator.sendBeacon('api.php?action=leave',f)});
const cv=$('#cv')[0],cx=cv.getContext('2d');let objs=[],gotFull=0,tool='pen',col='#111827',sz=6,cur=null,seq=0;
const canB=()=>+S.me.mgr||+S.me.can_board,SHAPE=['line','arrow','rect','ell'];
function drawObj(o){cx.save();cx.lineCap=cx.lineJoin='round';cx.strokeStyle=cx.fillStyle=o.c;cx.lineWidth=o.w;
 if(o.t=='hl'){cx.globalAlpha=.35;cx.lineWidth=o.w*4}if(o.t=='er'){cx.globalCompositeOperation='destination-out';cx.lineWidth=o.w*4}
 const P=o.p.map(q=>[q[0]*W,q[1]*H]);
 if(o.t=='text'){const rtl=/[\u0600-\u06FF]/.test(o.s);cx.font=o.fs+'px Vazirmatn,Tahoma,sans-serif';cx.direction=rtl?'rtl':'ltr';cx.textAlign=rtl?'right':'left';cx.textBaseline='top';cx.fillText(o.s,P[0][0],P[0][1])}
 else if(SHAPE.includes(o.t)){const[a,b]=P;cx.beginPath();
  if(o.t=='rect')cx.rect(a[0],a[1],b[0]-a[0],b[1]-a[1]);else if(o.t=='ell')cx.ellipse((a[0]+b[0])/2,(a[1]+b[1])/2,Math.abs(b[0]-a[0])/2,Math.abs(b[1]-a[1])/2,0,0,7);
  else{cx.moveTo(...a);cx.lineTo(...b);if(o.t=='arrow'){const g=Math.atan2(b[1]-a[1],b[0]-a[0]),l=14+o.w*2;[2.6,-2.6].forEach(d=>{cx.moveTo(...b);cx.lineTo(b[0]+l*Math.cos(g+d*.6),b[1]+l*Math.sin(g+d*.6))})}}cx.stroke()}
 else{cx.beginPath();P.forEach((q,i)=>i?cx.lineTo(...q):cx.moveTo(...q));if(P.length==1)cx.lineTo(P[0][0]+.1,P[0][1]);cx.stroke()}cx.restore()}
function redraw(x){cx.clearRect(0,0,W,H);objs.forEach(drawObj);x&&drawObj(x)}
const pos=e=>{const r=cv.getBoundingClientRect();return[Math.round((e.clientX-r.left)/r.width*1e4)/1e4,Math.round((e.clientY-r.top)/r.height*1e4)/1e4]};
function commit(o){objs.push(o);redraw();sig(0,'wbadd',o)}
$(cv).on('pointerdown',e=>{if(!canB())return;e.preventDefault();const p=pos(e);
 if(tool=='text'){const s=prompt(t('tool.prompt'));if(s)commit({id:S.pid+'-'+(++seq),u:S.pid,t:'text',c:col,w:sz,p:[p],s:s.slice(0,200),fs:sz*3+16});return}
 cv.setPointerCapture(e.pointerId);cur={id:S.pid+'-'+(++seq),u:S.pid,t:tool,c:col,w:sz,p:SHAPE.includes(tool)?[p,p]:[p]}})
.on('pointermove',e=>{if(!cur)return;const p=pos(e);if(cur.t=='pen'){const l=cur.p[cur.p.length-1];if(Math.hypot((p[0]-l[0])*W,(p[1]-l[1])*H)<2)return;drawObj({...cur,p:[l,p]});cur.p.push(p)}
 else if(SHAPE.includes(cur.t)){cur.p[1]=p;redraw(cur)}else{const l=cur.p[cur.p.length-1];if(Math.hypot((p[0]-l[0])*W,(p[1]-l[1])*H)<2)return;cur.p.push(p);redraw(cur)}})
.on('pointerup pointercancel',()=>{if(!cur)return;const o=cur;cur=null;commit(o)});
$('[data-tool]').click(function(){tool=$(this).data('tool');$('[data-tool]').removeClass('on');$(this).addClass('on')});
$('[data-c]').click(function(){col=$(this).data('c');$('[data-c]').removeClass('on');$(this).addClass('on')});$('#wc').on('input',function(){col=this.value;$('[data-c]').removeClass('on')});
$('[data-sz]').click(function(){sz=+$(this).data('sz');$('[data-sz]').removeClass('on');$(this).addClass('on')});
$('#wundo').click(()=>{for(let i=objs.length-1;i>=0;i--)if(objs[i].u==S.pid){const o=objs.splice(i,1)[0];redraw();sig(0,'wbundo',{id:o.id});return}});
$('#wgrid').click(function(){$(this).toggleClass('on');$('#cv').toggleClass('ng',!$(this).hasClass('on'))});
$('#wclr').click(()=>confirm(t('confirm.board'))&&(objs=[],redraw(),sig(0,'wbclear',1)));
$('#wsave').click(()=>{const c=document.createElement('canvas');c.width=W;c.height=H;const x=c.getContext('2d');x.fillStyle='#fff';x.fillRect(0,0,W,H);x.drawImage(cv,0,0);const a=document.createElement('a');a.download='whiteboard.png';a.href=c.toDataURL('image/png');a.click()});
function mixAdd(st){if(rec&&st&&st.getAudioTracks().length)try{rec.ctx.createMediaStreamSource(st).connect(rec.dest)}catch(e){}}
function paintBoard(x,w,h){x.fillStyle='#fff';x.fillRect(0,0,w,h);const k=Math.min(w/W,h/H);x.drawImage(cv,(w-W*k)/2,(h-H*k)/2,W*k,H*k)}
function recBar(){if(!rec||!rec.show){$('#recbar').addClass('na');return}const tot=rec.total||0,done=(rec.sent||0)+(rec.fly||0),p=rec.proc?100:(tot?Math.min(100,Math.round(done/tot*100)):0);if(!rec.proc&&tot<1){$('#recbar').addClass('na');return}$('#recbar').removeClass('na');$('#recbar .recfill').css('width',p+'%');$('#recpct').text(rec.proc?t('rec.proc'):t('rec.up',{n:locn(p)}))}
function frame(rc){const x=rc.getContext('2d'),w=rc.width,h=rc.height;x.fillStyle='#0f172a';x.fillRect(0,0,w,h);
 if(view=='board'){try{paintBoard(x,w,h)}catch(e){}return}
 let vs=$('#scrs video').toArray().filter(v=>v.readyState>=2&&v.videoWidth);if(!vs.length)vs=$('#tiles video').toArray().filter(v=>v.readyState>=2&&v.videoWidth);
 if(!vs.length){try{paintBoard(x,w,h)}catch(e){}return}
 const c=Math.ceil(Math.sqrt(vs.length)),rw=Math.ceil(vs.length/c),cw=w/c,ch=h/rw;
 vs.forEach((v,i)=>{try{const k=Math.min(cw/v.videoWidth,ch/v.videoHeight);x.drawImage(v,(i%c)*cw+(cw-v.videoWidth*k)/2,Math.floor(i/c)*ch+(ch-v.videoHeight*k)/2,v.videoWidth*k,v.videoHeight*k)}catch(e){}})}
$('#bRec').click(async function(){if(rec){if(!rec.mr)return;rec.show=1;try{rec.mr.stop()}catch(e){}return}if(!S.recOn)return toast(t('err.rec.off'));
 const mt=['video/webm;codecs=vp9,opus','video/webm;codecs=vp8,opus','video/webm','video/mp4'].find(x=>window.MediaRecorder&&MediaRecorder.isTypeSupported(x));if(!mt)return toast(t('err.rec.browser'));
 const ext=mt.indexOf('webm')>=0?'webm':'mp4';
 rec={starting:1};
 const ctx=new AudioContext();
 try{if(ctx.resume)await ctx.resume()}catch(e){}
 const dest=ctx.createMediaStreamDestination(),rc=document.createElement('canvas');rc.width=1280;rc.height=720;
 rc.setAttribute('aria-hidden','true');rc.style.cssText='position:fixed;left:-12000px;top:0;width:1280px;height:720px;opacity:0;pointer-events:none';document.body.appendChild(rc);
 frame(rc);
 let vtrack=null;
 try{if(!rc.captureStream)throw new Error('cap');const cap=rc.captureStream(0);vtrack=cap.getVideoTracks()[0];if(vtrack&&vtrack.requestFrame)vtrack.requestFrame()}catch(e){rec=null;ctx.close();rc.remove();return toast(t('err.rec.browser'))}
 const tracks=[];if(vtrack)tracks.push(vtrack);dest.stream.getAudioTracks().forEach(tr=>tracks.push(tr));
 if(!tracks.length){rec=null;ctx.close();rc.remove();return toast(t('err.rec.browser'))}
 let mr;try{mr=new MediaRecorder(new MediaStream(tracks),{mimeType:mt,videoBitsPerSecond:1500000})}catch(e){rec=null;ctx.close();rc.remove();return toast(t('err.rec.browser'))}
 let id;try{id=(await api('rec_start',{ext:ext})).id}catch(e){rec=null;ctx.close();rc.remove();return}
 if(!id){rec=null;ctx.close();rc.remove();return toast(t('err.nosave'))}
 const r=rec={ctx,dest,id,n:0,q:Promise.resolve(),bad:0,mr,total:0,sent:0,fly:0,proc:0,show:0,rc};
 Object.values(pcs).forEach(o=>mixAdd(o.rs[0]));if(L.mic)mixAdd(new MediaStream([L.mic]));
 const tick=()=>{frame(rc);if(vtrack&&vtrack.requestFrame)try{vtrack.requestFrame()}catch(e){}};
 r.tm=setInterval(tick,66);
 mr.ondataavailable=e=>{if(!e.data||!e.data.size||!rec||rec!==r)return;if(e.data.size>16*1048576){r.bad=1;try{mr.stop()}catch(ex){}return}const blob=e.data,n=r.n++;r.total+=blob.size;if(r.show)recBar();
  r.q=r.q.then(()=>new Promise((res,rej)=>{const fd=new FormData();fd.append('pid',S.pid);fd.append('id',id);fd.append('n',n);fd.append('f',blob,'c.'+ext);if(window.CSRF)fd.append('csrf',window.CSRF);const x=new XMLHttpRequest();x.open('POST','api.php?action=rec_chunk');if(window.CSRF)x.setRequestHeader('X-CSRF',window.CSRF);x.upload.onprogress=ev=>{if(!rec||!rec.show||!ev.lengthComputable)return;rec.fly=ev.loaded;recBar()};x.onload=()=>{if(!(x.status>=200&&x.status<300)){rej();return}if(rec){rec.sent+=blob.size;rec.fly=0;if(rec.show)recBar()}res()};x.onerror=()=>rej();x.send(fd)})).catch(()=>{r.bad=1;try{if(mr.state==='recording')mr.stop()}catch(ex){}})};
 mr.onerror=()=>{r.bad=1;try{if(mr.state==='recording')mr.stop()}catch(e){}};
 mr.onstop=()=>{clearInterval(r.tm);const done=()=>{try{ctx.close()}catch(e){}try{rc.remove()}catch(e){}rec=null;setView(view);$('#bRec').removeClass('rec');$('#recbar').addClass('na');toast(t(r.bad?'err.nosave':'rec.saved'))};r.show=1;recBar();r.q.then(()=>{if(r.bad||r.total<32){r.bad=1;done();return}r.proc=1;recBar();$.ajax({url:'api.php?action=rec_finish',method:'POST',data:{pid:S.pid,id:r.id},dataType:'json'}).done(function(res){if(!res||!res.ok)r.bad=1}).fail(()=>{r.bad=1}).always(done)}).catch(()=>{r.bad=1;done()})};
 try{mr.start(5000)}catch(e){clearInterval(r.tm);ctx.close();rc.remove();rec=null;setView(view);return toast(t('err.rec.browser'))}
 setView(view);$('#bRec').addClass('rec');toast(t('rec.started'))});
});
