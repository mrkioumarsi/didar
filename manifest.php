<?php
require 'config.php';
header('Content-Type: application/manifest+json');
$b=setting('brand')?:'دیدار';
echo json_encode(['name'=>$b,'short_name'=>$b,'start_url'=>'index.php','display'=>'standalone','background_color'=>'#0f172a','theme_color'=>'#0f172a','dir'=>html_dir(),'lang'=>html_lang(),
'icons'=>[['src'=>'assets/icon-192.png','sizes'=>'192x192','type'=>'image/png','purpose'=>'any maskable'],['src'=>'assets/icon-512.png','sizes'=>'512x512','type'=>'image/png','purpose'=>'any maskable']]],JSON_UNESCAPED_UNICODE);
