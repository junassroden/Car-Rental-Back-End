<?php

/**
 * Runs ChatRetrieval (the code ChatController uses) on a fixture database, for the chatbot
 * evaluation. stdin: {"fixture_path": str, "requests": [{"op": "vocabulary", "is_admin": bool}
 * | {"op": "execute", "viewer": {"role", "user_id"}, "plan": {...}}]}. stdout: one result per request.
 */
require_once __DIR__ . '/support/ChatFixtureSource.php';

$input = json_decode(stream_get_contents(STDIN), true);
if (!is_array($input) || !is_array($input['requests'] ?? null)) {
    fwrite(STDERR, "Expected a JSON object with a requests array.\n");
    exit(2);
}
$fixture = json_decode((string) file_get_contents($input['fixture_path'] ?? __DIR__ . '/fixtures/chat_fixture_db.json'), true);

$out = array_map(static function ($request) use ($fixture) {
    // A request may replace fixture tables (changed prices, new cars, simulated failures).
    $source = new ChatFixtureSource(array_replace($fixture, is_array($request['fixture_overrides'] ?? null) ? $request['fixture_overrides'] : []));
    if (($request['op'] ?? null) === 'vocabulary') {
        // As in ChatController::answerFromDatabase, a failed vocabulary query means an empty vocabulary.
        try {
            return ChatRetrieval::vocabulary($source->vehicles(!empty($request['is_admin'])));
        } catch (Exception $exception) {
            return ChatRetrieval::vocabulary([]);
        }
    }
    $viewer = $request['viewer'] ?? [];
    return ChatRetrieval::execute($request['plan'] ?? [], [
        'role'    => ($viewer['role'] ?? '') === 'admin' ? 'admin' : 'customer',
        'user_id' => (int) ($viewer['user_id'] ?? 0),
    ], $source);
}, $input['requests']);


echo json_encode($out, JSON_UNESCAPED_UNICODE);
