<?php
require_once __DIR__ . '/../admin/virus_lib.php';

function assert_true(bool $cond, string $msg): void {
  if (!$cond) {
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
  }
}

$roles = virus_assign_roles([1,2,3,4,5]);
$virus = count(array_filter($roles, fn($r) => $r === 'virus'));
$antidote = count(array_filter($roles, fn($r) => $r === 'antidote'));
assert_true(abs($virus - $antidote) <= 1, 'balance 50/50 con impar');

[$a, $b] = virus_pair_ids(9, 2);
assert_true($a === 2 && $b === 9, 'orden de pares');

$r1 = virus_resolve_combat(['player_id'=>1,'role'=>'virus','power'=>2], ['player_id'=>2,'role'=>'virus','power'=>3]);
assert_true($r1['post_state']['me']['power'] === 3 && $r1['post_state']['other']['power'] === 4, 'mismo rol +1');

$r2 = virus_resolve_combat(['player_id'=>1,'role'=>'virus','power'=>5], ['player_id'=>2,'role'=>'antidote','power'=>2]);
assert_true($r2['post_state']['me']['power'] === 4 && $r2['post_state']['other']['power'] === 1, 'virus vs antidoto gana mayor');

$r3 = virus_resolve_combat(['player_id'=>1,'role'=>'antidote','power'=>4], ['player_id'=>2,'role'=>'virus','power'=>4]);
assert_true($r3['post_state']['me']['power'] === 4 && $r3['post_state']['other']['power'] === 4, 'empate sin cambios');

$payload = [
  'session_id' => 5,
  'player_id' => 10,
  'nonce' => 'abc',
  'exp' => time() + 60,
];
$secret = 'secret-secret-secret';
$signed = virus_sign_payload($payload, $secret);
$decoded = virus_verify_payload_string($signed, $secret);
assert_true($decoded['session_id'] === 5 && $decoded['player_id'] === 10, 'firma/validacion QR');

echo "OK\n";
