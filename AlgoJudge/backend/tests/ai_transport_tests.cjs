// Offline transport integration: synthetic fixtures, local HTTP only, no .env or DB.
const http = require('node:http');
const { spawn } = require('node:child_process');
const assert = require('node:assert/strict');
const path = require('node:path');
const php = process.env.PHP_BIN || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
const root = path.resolve(__dirname, '../..');
const fixture = {
  title: 'Tổng hai số', topic: 'Cơ bản', difficulty: 'Easy',
  description: 'Cho hai số nguyên a và b. Tính tổng của chúng.',
  input_desc: 'Hai số nguyên a, b trên một dòng, -100 ≤ a, b ≤ 100.',
  output_desc: 'In tổng a + b.', sample_input: '2 3', sample_output: '5',
  assumptions: ['Giới hạn giá trị được bổ sung để giảng viên duyệt.'],
  test_cases: [
    {stdin:'2 3',expected:'5',is_public:true},
    {stdin:'-100 -100',expected:'-200',is_public:false,is_boundary:true},
    {stdin:'-2 3',expected:'1',is_public:false},
  ],
};
const partialDraft = {title:fixture.title, description:fixture.description};
const placeholderDraft = {
  ...fixture, input_desc:'Theo đề bài', output_desc:'Kết quả tương ứng',
  sample_input:'...', sample_output:'...',
};
const fullStatement = [
  fixture.description,
  '## Input', fixture.input_desc,
  '## Output', fixture.output_desc,
  '## Sample Input', fixture.sample_input,
  '## Sample Output', fixture.sample_output,
].join('\n');
const embeddedDraft = {
  ...fixture, description:fullStatement, input_desc:'', output_desc:'',
  sample_input:'', sample_output:'', test_cases:[],
};
let mode = 'complete';
let requests = [];
const server = http.createServer((req, res) => {
  let raw = '';
  req.on('data', data => raw += data);
  req.on('end', () => {
    try {
      const payload = JSON.parse(raw);
      requests.push(payload);
      assert.equal(req.url, '/v1/chat/completions');
      assert.equal(req.headers.authorization, 'Bearer offline-test-key');
      assert.equal(payload.model, 'offline-model');
      res.setHeader('Content-Type', 'application/json');
      // Deliberately accept the request without replying. cURL must stop at AI_TIMEOUT;
      // there are no timers or additional provider calls to hide an unbounded retry.
      if (mode === 'timeout' || (mode === 'repair-timeout' && requests.length > 1)) return;
      if (mode === 'quota' || (mode === 'repair-quota' && requests.length > 1) || requests.length > 2) {
        res.statusCode = 429;
        return res.end(JSON.stringify({error:{message:'secret-provider-message offline-test-key'}}));
      }
      let content = fixture;
      if (mode === 'chat' || mode === 'delayed-chat') content = 'Trợ lý cục bộ giả lập.';
      else if (mode === 'broken') content = '{"title":"broken';
      else if (mode === 'repair' && requests.length === 1) content = {...fixture, output_desc:''};
      else if (['partial-repair','repair-quota','repair-timeout','delayed-repair'].includes(mode) && requests.length === 1) content = partialDraft;
      else if (mode === 'placeholders' && requests.length === 1) content = placeholderDraft;
      else if (mode === 'embedded' && requests.length === 1) content = embeddedDraft;
      else if (mode === 'twice-partial') content = requests.length === 1 ? partialDraft : {...partialDraft, input_desc:fixture.input_desc};
      if (typeof content !== 'string') content = JSON.stringify(content);
      const response = JSON.stringify({choices:[{finish_reason:mode === 'cutoff' ? 'length' : 'stop', message:{content}}]});
      if (mode === 'delayed' || mode === 'delayed-chat' || (mode === 'delayed-repair' && requests.length > 1)) {
        return setTimeout(() => { if (!res.destroyed) res.end(response); }, 6000);
      }
      res.end(response);
    } catch (error) {
      res.statusCode = 500;
      res.end(JSON.stringify({error:{message:error.message}}));
    }
  });
});
function runPHP(code, envOverrides = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(php, ['-r', `require 'backend/lib/AIHelper.php'; ${code}`], {
      cwd: root,
      env: {...process.env, AI_PROVIDER:'openai', OPENAI_API_KEY:'offline-test-key', AI_MODEL:'offline-model',
        AI_BASE_URL:`http://127.0.0.1:${server.address().port}/v1`, AI_TIMEOUT:'5', ...envOverrides},
      stdio:['ignore','pipe','pipe'], timeout:15000, killSignal:'SIGKILL',
    });
    let out = '', err = '';
    child.stdout.on('data', data => out += data);
    child.stderr.on('data', data => err += data);
    child.on('error', reject);
    child.on('close', (code, signal) => {
      if (code !== 0) return reject(new Error(`PHP ${code} (${signal || 'exit'}): ${err}${out}`));
      try { resolve(JSON.parse(out)); } catch (error) { reject(new Error(`Invalid PHP JSON: ${err}${out}`, {cause:error})); }
    });
  });
}
function parse(rawText = 'Given two integers, calculate their sum.') {
  const encoded = Buffer.from(rawText, 'utf8').toString('base64');
  return runPHP(`try {
    echo json_encode(AIHelper::parseProblem(base64_decode('${encoded}')), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  } catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  }`);
}
function assertNoSecrets(result) {
  assert.ok(!JSON.stringify(result).includes('offline-test-key'));
  assert.ok(!JSON.stringify(result).includes('secret-provider-message'));
}
function assertReady(result) {
  assert.equal(result.source, 'ai');
  assert.equal(result.data.ready_for_publish, true);
  assert.equal(result.data.needs_review, false);
  for (const field of ['title','description','input_desc','output_desc','sample_input','sample_output']) {
    assert.equal(typeof result.data[field], 'string', field);
    assert.ok(result.data[field].trim(), `${field} must be nonempty`);
    assert.ok(!/^(?:\.{3}|…)$/u.test(result.data[field].trim()), `${field} must not be a placeholder`);
  }
  const cases = result.data.test_cases;
  assert.ok(cases.some(test => test.is_public && test.stdin === result.data.sample_input && test.expected === result.data.sample_output));
  assert.ok(cases.some(test => !test.is_public && test.is_boundary));
  assert.ok(cases.some(test => !test.is_public && !test.is_boundary));
  assertNoSecrets(result);
}
function assertFailure(result, messagePattern) {
  assert.deepEqual(Object.keys(result), ['error'], 'incomplete drafts must throw, not return successful previews');
  assert.equal(typeof result.error, 'string');
  assert.ok(result.error.trim(), 'failure must explain why completion failed');
  if (messagePattern) assert.match(result.error, messagePattern);
  assertNoSecrets(result);
}
function repairPrompt() {
  assert.equal(requests.length, 2, 'completion gets exactly one repair, never an unbounded retry');
  assert.equal(requests[1].response_format.type, 'json_object');
  return requests[1].messages.filter(message => message.role === 'user').map(message => message.content).join('\n');
}
function previousDraft(prompt) {
  const marker = '<BAN_NHAP_JSON>';
  const markerIndex = prompt.indexOf(marker);
  assert.notEqual(markerIndex, -1, 'repair prompt must include the previous draft as JSON');
  const json = prompt.slice(markerIndex + marker.length).trimStart();
  assert.equal(json[0], '{');
  // Read just the marked JSON object, independent of any repair prose after it.
  let depth = 0, quoted = false, escaped = false;
  for (let i = 0; i < json.length; i++) {
    const char = json[i];
    if (quoted) {
      if (escaped) escaped = false;
      else if (char === '\\') escaped = true;
      else if (char === '"') quoted = false;
    } else if (char === '"') quoted = true;
    else if (char === '{') depth++;
    else if (char === '}' && --depth === 0) return JSON.parse(json.slice(0, i + 1));
  }
  assert.fail('repair prompt contains an incomplete previous-draft JSON object');
}
async function main() {
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  let passed = 0, failed = 0;
  async function scenario(nextMode, label, test) {
    mode = nextMode; requests = [];
    try {
      await test();
      passed++;
      console.log(`PASS ${label}`);
    } catch (error) {
      failed++;
      console.error(`FAIL ${label}\n${error.stack}`);
    }
  }
  try {
    await scenario('complete', 'unset timeout defaults to unlimited; explicit positive timeout stays bounded', async () => {
      assert.equal(await runPHP(`echo json_encode(AIHelper::config()['timeout']);`, {AI_TIMEOUT:''}), 0);
      assert.equal(await runPHP(`echo json_encode(AIHelper::config()['timeout']);`, {AI_TIMEOUT:'0'}), 0);
      assert.equal(await runPHP(`echo json_encode(AIHelper::config()['timeout']);`, {AI_TIMEOUT:'5'}), 5);
    });
    for (const delayMode of ['delayed', 'delayed-repair', 'delayed-chat']) {
      await scenario(delayMode, `${delayMode} waits for completion with AI_TIMEOUT=0 and PHP timer disabled`, async () => {
        const isChat = delayMode === 'delayed-chat';
        const result = await runPHP(`ini_set('max_execution_time', '1');
          $result = ${isChat ? "AIHelper::chat('Trả lời tiếng Việt.', 'Xin chào')" : "AIHelper::parseProblem('Cho hai số nguyên. Tính tổng.')"};
          echo json_encode(['result' => $result, 'php_limit' => (int)ini_get('max_execution_time')], JSON_UNESCAPED_UNICODE);`, {AI_TIMEOUT:'0'});
        assert.equal(result.php_limit, 0, 'unlimited AI calls must also disable the PHP execution deadline');
        if (isChat) assert.equal(result.result, 'Trợ lý cục bộ giả lập.');
        else assertReady(result.result);
        assert.equal(requests.length, delayMode === 'delayed-repair' ? 2 : 1);
      });
    }
    await scenario('complete', 'provider transport and structured Vietnamese fixture', async () => {
      const result = await parse();
      assertReady(result);
      assert.equal(requests.length, 1);
      assert.equal(result.data.title, fixture.title);
      assert.equal(result.data.test_cases[0].expected, '5');
      assert.equal(requests[0].response_format.type, 'json_object');
      assert.match(requests[0].messages[0].content, /TIẾNG VIỆT/);
      assert.match(requests[0].messages[0].content, /TỰ SINH/);
    });
    await scenario('repair', 'one bounded repair for a missing output format', async () => {
      const result = await parse();
      assertReady(result);
      const prompt = repairPrompt();
      assert.match(prompt, /Thiếu định dạng đầu ra/);
      assert.equal(previousDraft(prompt).output_desc, '');
    });
    await scenario('partial-repair', 'title-and-description-only reply is completed using prior JSON and explicit missing labels', async () => {
      const result = await parse();
      assertReady(result);
      const prompt = repairPrompt();
      const draft = previousDraft(prompt);
      assert.equal(draft.title, partialDraft.title);
      assert.equal(draft.description, partialDraft.description);
      for (const field of ['input_desc','output_desc','sample_input','sample_output']) assert.equal(draft[field] || '', '');
      assert.deepEqual(draft.test_cases || [], []);
      for (const label of ['Thiếu định dạng đầu vào','Thiếu định dạng đầu ra','Thiếu ví dụ đầu vào','Thiếu ví dụ đầu ra','Thiếu test case hoàn chỉnh']) {
        assert.ok(prompt.includes(label), `repair prompt must explicitly report: ${label}`);
      }
    });
    await scenario('placeholders', 'generic prose and ellipsis examples trigger repair despite otherwise complete test cases', async () => {
      const result = await parse();
      assertReady(result);
      const prompt = repairPrompt();
      const draft = previousDraft(prompt);
      assert.equal(draft.title, fixture.title);
      assert.equal(draft.description, fixture.description);
      assert.match(prompt, /đầu vào/);
      assert.match(prompt, /đầu ra/);
      assert.equal(result.data.input_desc, fixture.input_desc);
      assert.equal(result.data.output_desc, fixture.output_desc);
      assert.equal(result.data.sample_input, fixture.sample_input);
      assert.equal(result.data.sample_output, fixture.sample_output);
    });
    await scenario('embedded', 'full statement is canonicalized before repairing missing hidden tests', async () => {
      const result = await parse();
      assertReady(result);
      const prompt = repairPrompt();
      const draft = previousDraft(prompt);
      for (const field of ['description','input_desc','output_desc','sample_input','sample_output']) assert.equal(draft[field], fixture[field], field);
      assert.equal(draft.test_cases.length, 1);
      assert.equal(draft.test_cases[0].is_public, true);
      assert.equal(draft.test_cases[0].stdin, fixture.sample_input);
      assert.equal(draft.test_cases[0].expected, fixture.sample_output);
      assert.match(prompt, /test ẩn/);
      assert.doesNotMatch(prompt, /Thiếu (?:định dạng|ví dụ) đầu (?:vào|ra)/);
      assert.doesNotMatch(result.data.description, /(?:^|\n)\s*#*\s*(?:Input|Output|Sample)/i);
    });
    await scenario('twice-partial', 'two partial replies fail instead of returning a successful incomplete draft', async () => {
      const result = await parse();
      assert.equal(requests.length, 2);
      assertFailure(result, /thiếu|chưa|không|đầy đủ|hoàn tất/iu);
    });
    await scenario('repair-quota', 'repair HTTP 429 preserves a meaningful error and stops after two calls', async () => {
      const result = await parse();
      assert.equal(requests.length, 2);
      assertFailure(result, /429|hạn mức|giới hạn/iu);
    });
    for (const failure of ['quota','broken','cutoff']) {
      await scenario(failure, `${failure} throws for incomplete raw input without leaking credentials`, async () => {
        const result = await parse();
        assert.equal(requests.length, failure === 'broken' ? 2 : 1, 'only malformed JSON gets one bounded repair; transport failures do not retry');
        assertFailure(result, failure === 'quota' ? /429|hạn mức|giới hạn/iu
          : failure === 'cutoff' ? /cắt|giới hạn|length|token/iu : undefined);
      });
    }
    await scenario('quota', 'complete local statement with a real sample remains review-only after provider failure', async () => {
      const result = await parse(`${fixture.title}\n${fullStatement}`);
      assert.equal(requests.length, 1);
      assert.equal(result.source, 'local');
      assert.equal(result.data.ready_for_publish, false);
      assert.equal(result.data.needs_review, true);
      assert.ok(result.data.warnings.length);
      for (const field of ['input_desc','output_desc','sample_input','sample_output']) assert.equal(result.data[field], fixture[field], field);
      assert.equal(result.data.test_cases.length, 1, 'local fallback must not fabricate hidden tests');
      assert.equal(result.data.test_cases[0].is_public, true);
      assertNoSecrets(result);
    });
    for (const timeoutMode of ['timeout', 'repair-timeout']) {
      await scenario(timeoutMode, `${timeoutMode} remains a sanitized transport error, not a missing-content error`, async () => {
        const result = await runPHP(`ini_set('max_execution_time', '1'); try {
          AIHelper::parseProblem('Cho hai số nguyên. Tính tổng.');
          echo json_encode(['unexpected' => 'success']);
        } catch (AIRequestException $e) {
          echo json_encode(['error' => $e->getMessage(), 'code' => $e->reason(), 'status' => $e->httpStatus()]);
        }`);
        assert.equal(requests.length, timeoutMode === 'timeout' ? 1 : 2, 'timeout never triggers an automatic retry');
        assert.equal(result.code, 'ai_timeout');
        assert.equal(result.status, 504);
        assert.match(result.error, /5 giây/);
        assert.ok(!result.error.includes('Các mục cần sinh'));
        assert.ok(!result.error.includes('Thiếu định dạng'));
        assertNoSecrets(result);
      });
    }
    await scenario('timeout', 'complete source can still recover locally after a timeout without fabricating tests', async () => {
      const result = await parse(`${fixture.title}\n${fullStatement}`);
      assert.equal(requests.length, 1);
      assert.equal(result.source, 'local');
      assert.equal(result.data.needs_review, true);
      assert.equal(result.data.ready_for_publish, false);
      assert.equal(result.data.test_cases.length, 1);
      assert.match(result.data.warnings.join(' '), /5 giây/);
      assertNoSecrets(result);
    });
    await scenario('chat', 'chat respects configured provider without JSON mode', async () => {
      const result = await runPHP(`echo json_encode(AIHelper::chat('Trả lời tiếng Việt.', 'Xin chào'));`);
      assert.equal(result, 'Trợ lý cục bộ giả lập.');
      assert.equal(requests.length, 1);
      assert.equal(requests[0].response_format, undefined);
    });
    console.log(`AI transport scenarios: ${passed} passed, ${failed} failed.`);
    assert.equal(failed, 0, 'offline AI transport regressions failed');
  } finally { server.close(); }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
