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
    if (!$GLOBALS['notes_fixture']['write_success']) {
        if (isset($GLOBALS['notes_fixture']['failed_write_hook'])) ($GLOBALS['notes_fixture']['failed_write_hook'])();
        return false;
    }
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
function wp_date(string $format): string {
    notes_expect($format === 'Y-m-d', 'Scheduling uses the existing site-local day format');
    return '2030-01-01';
}
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

// Extend the same isolated endpoint suite with confirmed rename coverage.
notes_reset();
$other = $GLOBALS['notes_fixture']['meta'][8];
$response = notes_action(['operation'=>'rename','plan_id'=>'owned-plan','title'=>'  <b>Confirmed name</b>  ']);
notes_expect($response->success && $response->data['title'] === 'Confirmed name', 'Rename returns the sanitized canonical title');
notes_expect($response->data['title'] === notes_saved()['title'], 'Returned title matches the saved value');
notes_expect(!array_key_exists('notes', $response->data) && notes_saved()['notes'] === 'Old private notes', 'Rename neither returns nor changes private notes');
notes_expect($GLOBALS['notes_fixture']['meta'][8] === $other, 'Renaming preserves the other owner');
$response = notes_action(['operation'=>'rename','plan_id'=>'owned-plan','title'=>str_repeat('n',110)]);
notes_expect(strlen($response->data['title']) === 100 && $response->data['title'] === notes_saved()['title'], 'Returned title follows the existing saved length limit');

notes_reset();
$response = notes_action(['operation'=>'rename','plan_id'=>'owned-plan','title'=>' <b></b> ']);
notes_expect(!$response->success && $response->status === 400 && $GLOBALS['notes_fixture']['writes'] === 0, 'An empty sanitized name is rejected');
notes_reset();
$GLOBALS['notes_fixture']['write_success'] = false;
$response = notes_action(['operation'=>'rename','plan_id'=>'owned-plan','title'=>'Name that did not persist']);
notes_expect(!$response->success && $response->status === 500 && notes_saved()['title'] === 'My plan', 'A failed rename cannot confirm an unsaved name');
notes_expect(!array_key_exists('title', $response->data) && !array_key_exists('notes', $response->data), 'Failed rename returns no saved fields');
$response = notes_action(['operation'=>'rename','plan_id'=>'owned-plan','title'=>'My plan']);
notes_expect($response->success && $response->data['title'] === 'My plan', 'An unchanged stored name is a verified no-op');

foreach ([['operation'=>'notes','notes'=>'New note'],['operation'=>'readiness','readiness_key'=>'route','checked'=>'1'],['operation'=>'packing','packing_key'=>'water','checked'=>'1']] as $fields) {
    notes_reset();
    $response = notes_action($fields + ['plan_id'=>'owned-plan']);
    notes_expect($response->success && !array_key_exists('title', $response->data), 'Canonical title is returned only for rename operations');
}
notes_reset();
$response = notes_action(['operation'=>'rename','plan_id'=>'other-plan','title'=>'Unauthorized name']);
notes_expect(!$response->success && $response->status === 404 && $GLOBALS['notes_fixture']['writes'] === 0, 'Another owner plan cannot be renamed');
notes_expect(!array_key_exists('title', $response->data), 'Unauthorized rename discloses no title');
foreach (['logged_in'=>401,'nonce_valid'=>403] as $flag=>$status) {
    notes_reset();
    $GLOBALS['notes_fixture'][$flag] = false;
    $response = notes_action(['operation'=>'rename','plan_id'=>'owned-plan','title'=>'Denied rename']);
    notes_expect(!$response->success && $response->status === $status, 'Rename authentication and nonce checks remain enforced');
    notes_expect($GLOBALS['notes_fixture']['reads'] === 0 && $GLOBALS['notes_fixture']['writes'] === 0, 'Denied rename accesses no metadata');
    notes_expect(!array_key_exists('title', $response->data), 'Denied rename returns no title');
}
fwrite(STDOUT, "TN Game OS 5.161.0 private rename endpoint tests passed\n");

foreach (['readiness'=>['route','gear'], 'packing'=>['water','layers']] as $kind=>$keys) {
    [$key,$other_key] = $keys;
    foreach ([true,false] as $checked) {
        foreach ([true,false] as $write_success) {
            notes_reset();
            $initial = $checked ? [$other_key=>1] : [$other_key=>1,$key=>1];
            $expected = $checked ? [$other_key=>1,$key=>1] : [$other_key=>1];
            $GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'][0][$kind] = $initial;
            $GLOBALS['notes_fixture']['write_success'] = $write_success;
            $other_owner = $GLOBALS['notes_fixture']['meta'][8];
            $response = notes_action(['operation'=>$kind,'plan_id'=>'owned-plan',$kind.'_key'=>$key,'checked'=>$checked?'1':'0']);
            notes_expect($response->success === $write_success, 'Checklist success must match persistence for checking and unchecking');
            notes_expect($response->status === ($write_success ? 200 : 500), 'Failed checklist writes report a server error');
            notes_expect(notes_saved()[$kind] == ($write_success ? $expected : $initial), 'Checklist persistence preserves other items');
            notes_expect($GLOBALS['notes_fixture']['writes'] === 1, 'Checklist save does not automatically retry');
            notes_expect($GLOBALS['notes_fixture']['meta'][8] === $other_owner, 'Another owner checklist remains untouched');
            notes_expect(notes_saved()['notes'] === 'Old private notes', 'Checklist updates preserve private notes');
            notes_expect(array_diff(array_keys($response->data), ['message','url']) === [], 'Checklist responses disclose no private plan fields');
        }
        notes_reset();
        $GLOBALS['notes_fixture']['write_success'] = false;
        $GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'][0][$kind] = $checked ? [$key=>1,$other_key=>1] : [$other_key=>1];
        $response = notes_action(['operation'=>$kind,'plan_id'=>'owned-plan',$kind.'_key'=>$key,'checked'=>$checked?'1':'0']);
        notes_expect($response->success, 'An already stored checkbox value is a verified no-op');
    }

    notes_reset();
    $GLOBALS['notes_fixture']['write_success'] = false;
    $response = notes_action(['operation'=>$kind,'plan_id'=>'owned-plan',$kind.'_key'=>$key,'checked'=>'0']);
    notes_expect($response->success, 'An absent unchecked list is a valid no-op');
    notes_reset();
    $GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'][0][$kind] = [$key=>1];
    $response = notes_action(['operation'=>$kind,'plan_id'=>'owned-plan',$kind.'_key'=>$key,'checked'=>'0']);
    notes_expect($response->success && !array_key_exists($kind,notes_saved()), 'Unchecking the last item clears the checklist');

    notes_reset();
    $GLOBALS['notes_fixture']['write_success'] = false;
    $GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'][0][$kind] = [$key=>1];
    $response = notes_action(['operation'=>$kind,'plan_id'=>'owned-plan',$kind.'_key'=>$key,'checked'=>'0']);
    notes_expect(!$response->success && $response->status === 500 && notes_saved()[$kind] === [$key=>1], 'Failed removal of the last item cannot confirm an empty list');

    notes_reset();
    $GLOBALS['notes_fixture']['write_success'] = false;
    $GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'][0][$kind] = [$key=>1,$other_key=>1];
    $GLOBALS['notes_fixture']['failed_write_hook'] = static function() use ($kind,$key,$other_key): void {
        $GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'][0][$kind] = [$other_key=>1,$key=>1];
    };
    $response = notes_action(['operation'=>$kind,'plan_id'=>'owned-plan',$kind.'_key'=>$key,'checked'=>'1']);
    notes_expect($response->success, 'Equivalent checklist key order is a valid no-op');

    notes_reset();
    $GLOBALS['notes_fixture']['write_success'] = false;
    $GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'][0][$kind] = [$key=>1,$other_key=>1];
    $GLOBALS['notes_fixture']['failed_write_hook'] = static function() use ($kind,$key): void {
        $GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'][0][$kind] = [$key=>1];
    };
    $response = notes_action(['operation'=>$kind,'plan_id'=>'owned-plan',$kind.'_key'=>$key,'checked'=>'1']);
    notes_expect(!$response->success && $response->status === 500, 'No-op verification checks the entire checklist, not only the submitted item');

    notes_reset();
    $GLOBALS['notes_fixture']['write_success'] = false;
    $GLOBALS['notes_fixture']['failed_write_hook'] = static function(): void {
        $GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'] = [];
    };
    $response = notes_action(['operation'=>$kind,'plan_id'=>'owned-plan',$kind.'_key'=>$key,'checked'=>'0']);
    notes_expect(!$response->success && $response->status === 500, 'A disappeared plan cannot be mistaken for an empty saved checklist');

    foreach (['invalid_key','archived','unscheduled','other_owner','logged_in','nonce_valid'] as $scenario) {
        notes_reset();
        $fields = ['operation'=>$kind,'plan_id'=>'owned-plan',$kind.'_key'=>$key,'checked'=>'1'];
        $expected_status = 409;
        if ($scenario === 'invalid_key') { $fields[$kind.'_key'] = 'unsupported'; $expected_status = 400; }
        if ($scenario === 'archived') $GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'][0]['archived_at'] = 1;
        if ($scenario === 'unscheduled') unset($GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'][0]['planned_date']);
        if ($scenario === 'other_owner') { $fields['plan_id'] = 'other-plan'; $expected_status = 404; }
        if ($scenario === 'logged_in' || $scenario === 'nonce_valid') {
            $GLOBALS['notes_fixture'][$scenario] = false;
            $expected_status = $scenario === 'logged_in' ? 401 : 403;
        }
        $response = notes_action($fields);
        notes_expect(!$response->success && $response->status === $expected_status, 'Checklist access and validation guards stay enforced');
        notes_expect($GLOBALS['notes_fixture']['writes'] === 0, 'Rejected checklist requests perform no write');
        notes_expect(array_keys($response->data) === ['message'], 'Rejected checklist requests disclose no plan fields');
        if ($scenario === 'logged_in' || $scenario === 'nonce_valid') notes_expect($GLOBALS['notes_fixture']['reads'] === 0, 'Unauthenticated checklist requests access no metadata');
    }
}
fwrite(STDOUT, "TN Game OS 5.162.0 private prep checklist endpoint tests passed\n");

function schedule_reset(): void {
    notes_reset();
    $GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'][0] += [
        'readiness'=>['route'=>1], 'readiness_updated_at'=>12,
        'packing'=>['water'=>1], 'packing_updated_at'=>34,
    ];
}
foreach (['2030-01-01','2030-01-03',''] as $date) foreach ([true,false] as $write_success) {
    schedule_reset();
    $before = notes_saved();
    $other = $GLOBALS['notes_fixture']['meta'][8];
    $GLOBALS['notes_fixture']['write_success'] = $write_success;
    $response = notes_action(['operation'=>'schedule','plan_id'=>'owned-plan','planned_date'=>$date]);
    notes_expect($response->success === $write_success && $response->status === ($write_success ? 200 : 500), 'Date changes and removal require persistence');
    if ($write_success) {
        notes_expect((notes_saved()['planned_date'] ?? '') === $date, 'Date response follows the persisted schedule');
        foreach (['readiness','readiness_updated_at','packing','packing_updated_at'] as $field) notes_expect(!array_key_exists($field,notes_saved()), 'Changing the date clears checklist state and timestamps');
        notes_expect(notes_saved()['title'] === $before['title'] && notes_saved()['notes'] === $before['notes'], 'Scheduling leaves names and private notes intact');
    } else notes_expect(notes_saved() === $before, 'A failed schedule write leaves the old plan intact');
    notes_expect($GLOBALS['notes_fixture']['writes'] === 1, 'Scheduling never automatically retries');
    notes_expect($GLOBALS['notes_fixture']['meta'][8] === $other, 'Another owner schedule remains untouched');
    notes_expect(array_diff(array_keys($response->data), ['message','url']) === [], 'Schedule responses disclose no private plan fields');
}
foreach ([true,false] as $write_success) {
    schedule_reset();
    $before = notes_saved();
    $GLOBALS['notes_fixture']['write_success'] = $write_success;
    $response = notes_action(['operation'=>'schedule','plan_id'=>'owned-plan','planned_date'=>'2030-01-02']);
    notes_expect($response->success, 'Saving the same date is a valid unchanged schedule');
    foreach (['planned_date','readiness','readiness_updated_at','packing','packing_updated_at'] as $field) notes_expect(notes_saved()[$field] === $before[$field], 'Same-day saves preserve preparation state');
}
notes_reset();
unset($GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'][0]['planned_date']);
$GLOBALS['notes_fixture']['write_success'] = false;
$response = notes_action(['operation'=>'schedule','plan_id'=>'owned-plan','planned_date'=>'']);
notes_expect($response->success, 'Clearing an already absent date is a valid no-op');

// A date alone is insufficient: every intended checklist reset must be stored.
foreach (['readiness','readiness_updated_at','packing','packing_updated_at'] as $leftover) {
    schedule_reset();
    $GLOBALS['notes_fixture']['write_success'] = false;
    $GLOBALS['notes_fixture']['failed_write_hook'] = static function() use ($leftover): void {
        $plan =& $GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'][0];
        $plan['planned_date'] = '2030-01-03';
        foreach (['readiness','readiness_updated_at','packing','packing_updated_at'] as $field) if ($field !== $leftover) unset($plan[$field]);
    };
    $response = notes_action(['operation'=>'schedule','plan_id'=>'owned-plan','planned_date'=>'2030-01-03']);
    notes_expect(!$response->success && $response->status === 500, 'Incomplete checklist reset cannot confirm the date change');
}
schedule_reset();
$GLOBALS['notes_fixture']['write_success'] = false;
$GLOBALS['notes_fixture']['failed_write_hook'] = static function(): void {
    $plan =& $GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'][0];
    $plan['planned_date'] = '2030-01-03';
    unset($plan['readiness'],$plan['readiness_updated_at'],$plan['packing'],$plan['packing_updated_at']);
};
$response = notes_action(['operation'=>'schedule','plan_id'=>'owned-plan','planned_date'=>'2030-01-03']);
notes_expect($response->success, 'A fully verified stored schedule may succeed when update returns false');
schedule_reset();
$GLOBALS['notes_fixture']['write_success'] = false;
$GLOBALS['notes_fixture']['failed_write_hook'] = static function(): void {
    $GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'] = [];
};
$response = notes_action(['operation'=>'schedule','plan_id'=>'owned-plan','planned_date'=>'']);
notes_expect(!$response->success && $response->status === 500, 'A disappeared plan is not an unchanged empty schedule');

foreach (['2029-12-31','2030-02-29','2030-04-31','2030-13-01','2030-1-03','not-a-date'] as $date) {
    schedule_reset();
    $before = notes_saved();
    $response = notes_action(['operation'=>'schedule','plan_id'=>'owned-plan','planned_date'=>$date]);
    notes_expect(!$response->success && $response->status === 400 && $GLOBALS['notes_fixture']['writes'] === 0, 'Past, malformed, and nonexistent dates are rejected');
    notes_expect(notes_saved() === $before, 'Invalid dates cannot reset preparation');
}
schedule_reset();
$response = notes_action(['operation'=>'schedule','plan_id'=>'owned-plan','planned_date'=>'2032-02-29']);
notes_expect($response->success && notes_saved()['planned_date'] === '2032-02-29', 'Valid future leap dates remain supported');
foreach (['archived','other_owner','logged_in','nonce_valid'] as $scenario) foreach (['2030-01-03',''] as $date) {
    schedule_reset();
    $fields = ['operation'=>'schedule','plan_id'=>'owned-plan','planned_date'=>$date];
    if ($scenario === 'archived') { $GLOBALS['notes_fixture']['meta'][7]['tng_adventure_ai_plan_library'][0]['archived_at'] = 1; $expected_status = 409; }
    if ($scenario === 'other_owner') { $fields['plan_id'] = 'other-plan'; $expected_status = 404; }
    if ($scenario === 'logged_in' || $scenario === 'nonce_valid') { $GLOBALS['notes_fixture'][$scenario] = false; $expected_status = $scenario === 'logged_in' ? 401 : 403; }
    $response = notes_action($fields);
    notes_expect(!$response->success && $response->status === $expected_status && $GLOBALS['notes_fixture']['writes'] === 0, 'Setting and clearing dates preserve access restrictions');
    notes_expect(array_keys($response->data) === ['message'], 'Denied schedule requests disclose no plan fields');
    if ($scenario === 'logged_in' || $scenario === 'nonce_valid') notes_expect($GLOBALS['notes_fixture']['reads'] === 0, 'Unauthenticated scheduling accesses no metadata');
}
fwrite(STDOUT, "TN Game OS 5.163.0 private schedule endpoint tests passed\n");
