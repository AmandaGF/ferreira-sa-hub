<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__.'/core/database.php'; header('Content-Type: text/plain; charset=utf-8');
$pdo=db();
$c=$pdo->prepare("SELECT id,nome_contato,telefone,client_id FROM zapi_conversas WHERE id=79"); $c->execute(); $cv=$c->fetch();
print_r($cv);
if($cv && $cv['client_id']){
  $s=$pdo->prepare("SELECT id,title,status,drive_folder_url FROM cases WHERE client_id=? ORDER BY created_at DESC"); $s->execute(array($cv['client_id']));
  foreach($s->fetchAll() as $r){ echo "case #{$r['id']} | {$r['status']} | {$r['title']}\n  folder: {$r['drive_folder_url']}\n"; }
}
