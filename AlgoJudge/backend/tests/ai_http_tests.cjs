// Compatibility test entrypoint: the old raw-payload chatbot APIs were retired.
// The replacement suite drives real app HTTP + genuine SSE with a local fake provider.
// Run: AJ_DB_PORT=3307 node backend/tests/ai_http_tests.cjs
require('./chatbot_stream_tests.cjs');
