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
assert_true($r1['draw'] === true && $r1['matchup_type'] === 'VV', 'mismo rol es empate visual + matchup VV');

$r2 = virus_resolve_combat(['player_id'=>1,'role'=>'virus','power'=>5], ['player_id'=>2,'role'=>'antidote','power'=>2]);
assert_true($r2['post_state']['me']['power'] === 4 && $r2['post_state']['other']['power'] === 1, 'virus vs antidoto gana mayor');
assert_true($r2['winner_player_id'] === 1 && virus_player_view_result($r2, 1) === 'GANASTE', 'resultado ganador por jugador');
assert_true(virus_player_view_result($r2, 2) === 'PERDISTE', 'resultado perdedor por jugador');
assert_true(virus_player_outcome_code($r2, 1) === 'WIN' && virus_player_outcome_code($r2, 2) === 'LOSE', 'outcome code win/lose');
assert_true($r2['matchup_type'] === 'VA', 'matchup VA');

$r3 = virus_resolve_combat(['player_id'=>1,'role'=>'antidote','power'=>4], ['player_id'=>2,'role'=>'virus','power'=>4]);
assert_true($r3['post_state']['me']['power'] === 4 && $r3['post_state']['other']['power'] === 4, 'empate sin cambios');
assert_true(virus_player_view_result($r3, 1) === 'EMPATE', 'vista empate');
assert_true(virus_player_outcome_code($r3, 1) === 'DRAW', 'outcome code draw');

assert_true(virus_matchup_type(['me' => ['role' => 'antidote'], 'other' => ['role' => 'antidote']]) === 'AA', 'matchup helper AA');

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

$already = virus_build_already_interacted_error(99, [
  ['id' => 3, 'handle' => 'Lucas'],
  ['id' => 8, 'handle' => 'pepe44'],
]);
assert_true($already['code'] === 'ALREADY_INTERACTED', 'code already interacted');
assert_true($already['session_id'] === 99 && count($already['players']) === 2, 'payload already interacted con session y jugadores');

echo "OK\n";
