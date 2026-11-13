<?php
/** Compatibility entrypoint: same private draft + real SSE stream, no legacy bypass. */
require_once __DIR__ . '/../lib/Auth.php';
Auth::requireApiRole(['teacher','admin']);
require __DIR__ . '/ai_chat.php';
