<?php
declare(strict_types=1);

// In-memory WordPress stand-ins exercise the real endpoint. This is not a
// replacement implementation or conformance test for WordPress sanitizers.
define('ABSPATH', __DIR__ . '/');
require_once __DIR__ . '/../experiences/wordpress/tn-game-os/app/Core/class-container.php';
require_once __DIR__ . '/../experiences/wordpress/tn-game-os/app/Core/class-module-interface.php';
require_once __DIR__ . '/../experiences/wordpress/tn-game-os/app/Modules/Destinations/class-adventure-ai.php';

final class NotesTestResponse extends RuntimeException {
    public function __construct(public bool $success, public array $data, public int $status) { parent::__construct('Test response'); }
}
function notes_expect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function wp_send_json_success(array $data, ?int $status = null): never { throw new NotesTestResponse(true, $data, $status ?? 200); }
function wp_send_json_error(array $data, ?int $status = null): never { throw new NotesTestResponse(false, $data, $status ?? 200); }
function check_ajax_referer(string $action, string $field): void {
    notes_expect($action === 'tng_adventure_ai' && $field === 'nonce', 'Existing nonce verification must be preserved');
    $GLOBALS['notes_fixture']['nonce_checks']++;
    if (!$GLOBALS['notes_fixture']['nonce_valid']) wp_send_json_error(['message'=>'Invalid nonce'], 403);
}
function is_user_logged_in(): bool { return $GLOBALS['notes_fixture']['logged_in']; }
function get_current_user_id(): int { return 7; }
function get_user_meta(int $user, string $key, bool $single = false): mixed {
    $GLOBALS['notes_fixture']['reads']++;
    return $GLOBALS['notes_fixture']['meta'][$user][$key] ?? '';
}
function update_user_meta(int $user, string $key, mixed $value): bool {
    $GLOBALS['notes_fixture']['writes']++;
    if (!$GLOBALS['notes_fixture']['write_success']) return false;
    $GLOBALS['notes_fixture']['meta'][$user][$key] = $value;
    return true;
}
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function wp_unslash(string $value): string { return stripslashes($value); }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_textarea_field(string $value): string {
    $GLOBALS['notes_fixture']['sanitizations']++;
    return trim(strip_tags(str_replace("\r\n", "\n", $value)));
}
function absint(mixed $value): int { return abs((int)$value); }
function home_url(string $path): string { return 'https://tn.example' . $path; }
function notes_reset(): void {
    $GLOBALS['notes_fixture'] = [
        'logged_in'=>true, 'nonce_valid'=>true, 'write_success'=>true, 'nonce_checks'=>0, 'reads'=>0, 'writes'=>0, 'sanitizations'=>0,
        'meta'=>[
            7=>['tng_adventure_ai_plan_library'=>[['id'=>'owned-plan','ids'=>[11],'title'=>'My plan','notes'=>'Old private notes','planned_date'=>'2030-01-02']]],
            8=>['tng_adventure_ai_plan_library'=>[['id'=>'other-plan','ids'=>[22],'title'=>'Other plan','notes'=>'Other owner secret']]],
        ],
    ];
}
function notes_action(array $fields): NotesTestResponse {
    $_POST = $fields;
    try { (new \TNG_OS\Modules\Destinations\Adventure_AI())->ajax_library_action(); }
    catch (NotesTestResponse $response) { return $response; }
    throw new RuntimeException('Endpoint did not send a response');
}
function notes_saved(): array { return $GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'][0]; }

notes_reset();
$other = $GLOBALS['notes_fixture']['meta'][8];
$response = notes_action(['operation'=>'notes','plan_id'=>'owned-plan','notes'=>"  Park at <b>north gate</b>\nMeet at 9.  "]);
notes_expect($response->success && $response->status === 200, 'Owner can save notes');
notes_expect($response->data['notes'] === "Park at north gate\nMeet at 9.", 'Response contains sanitized saved text');
notes_expect($response->data['notes'] === notes_saved()['notes'], 'Response matches the persisted snapshot');
notes_expect($GLOBALS['notes_fixture']['sanitizations'] === 1, 'Existing notes sanitizer is called');
notes_expect($GLOBALS['notes_fixture']['writes'] === 1, 'Notes save performs one metadata write');
notes_expect($GLOBALS['notes_fixture']['nonce_checks'] === 1, 'Nonce verification runs before saving');
notes_expect($GLOBALS['notes_fixture']['meta'][8] === $other, 'Another owner is untouched');
notes_expect(!str_contains(json_encode($response->data), 'Other owner secret'), 'Another owner is not disclosed');

notes_reset();
$response = notes_action(['operation'=>'notes','plan_id'=>'owned-plan','notes'=>str_repeat('a',650)]);
notes_expect(strlen($response->data['notes']) === 600, 'Returned notes obey the existing length limit');
notes_expect($response->data['notes'] === notes_saved()['notes'], 'Truncated response matches saved notes');
$response = notes_action(['operation'=>'notes','plan_id'=>'owned-plan','notes'=>" \n "]);
notes_expect($response->data['notes'] === '' && !array_key_exists('notes', notes_saved()), 'Clearing notes returns an empty confirmed snapshot');

notes_reset();
$GLOBALS['notes_fixture']['write_success'] = false;
$response = notes_action(['operation'=>'notes','plan_id'=>'owned-plan','notes'=>'New notes that could not persist']);
notes_expect(!$response->success && $response->status === 500, 'Failed writes cannot confirm unsaved notes');
notes_expect(notes_saved()['notes'] === 'Old private notes' && !array_key_exists('notes', $response->data), 'Failed write retains old notes without returning a false saved snapshot');
$response = notes_action(['operation'=>'notes','plan_id'=>'owned-plan','notes'=>'Old private notes']);
notes_expect($response->success && $response->data['notes'] === 'Old private notes', 'An unchanged value is a valid no-op when persistence returns false');

foreach ([
    ['operation'=>'rename','title'=>'New title'],
    ['operation'=>'readiness','readiness_key'=>'route','checked'=>'1'],
    ['operation'=>'packing','packing_key'=>'water','checked'=>'1'],
] as $fields) {
    notes_reset();
    $response = notes_action($fields + ['plan_id'=>'owned-plan']);
    notes_expect($response->success && !array_key_exists('notes', $response->data), 'Non-notes updates must not return private notes');
}
notes_reset();
$response = notes_action(['operation'=>'notes','plan_id'=>'other-plan','notes'=>'Unauthorized edit']);
notes_expect(!$response->success && $response->status === 404 && $GLOBALS['notes_fixture']['writes'] === 0, 'Other owner plan IDs cannot be written');
notes_expect(!array_key_exists('notes', $response->data), 'Missing or unauthorized plan response contains no notes');
notes_reset();
$GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'][0]['archived_at'] = 1;
$response = notes_action(['operation'=>'notes','plan_id'=>'owned-plan','notes'=>'Archived edit']);
notes_expect(!$response->success && $response->status === 409 && $GLOBALS['notes_fixture']['writes'] === 0, 'Archived notes remain protected');
foreach (['logged_in'=>401,'nonce_valid'=>403] as $flag=>$status) {
    notes_reset();
    $GLOBALS['notes_fixture'][$flag] = false;
    $response = notes_action(['operation'=>'notes','plan_id'=>'owned-plan','notes'=>'Denied edit']);
    notes_expect(!$response->success && $response->status === $status, 'Authentication and nonce failures are preserved');
    notes_expect($GLOBALS['notes_fixture']['reads'] === 0 && $GLOBALS['notes_fixture']['writes'] === 0, 'Denied requests do not access plan metadata');
    notes_expect(!array_key_exists('notes', $response->data), 'Denied requests disclose no notes');
}
fwrite(STDOUT, "TN Game OS 5.160.0 private notes endpoint tests passed\n");
