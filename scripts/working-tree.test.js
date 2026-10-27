// Offline regression tests in a disposable repository; never modifies the project index.
const fs = require('fs');
const os = require('os');
const path = require('path');
const assert = require('assert/strict');
const { execFileSync, spawnSync } = require('child_process');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'kit-working-tree-'));
const git = (...args) => execFileSync('git', args, { cwd: temporary, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
const run = (script, ...args) => spawnSync(process.execPath, [path.join(temporary, 'scripts', script), ...args], { cwd: temporary, encoding: 'utf8' });
const hash = () => {
  const result = run('code-tree.js');
  assert.equal(result.status, 0, result.stderr);
  return result.stdout.trim();
};
try {
  fs.mkdirSync(path.join(temporary, 'scripts'));
  for (const file of ['approval-lib.js', 'working-tree.js', 'code-tree.js', 'check-clean.js']) {
    fs.copyFileSync(path.join(__dirname, file), path.join(temporary, 'scripts', file));
  }
  git('init', '-q');
  git('config', 'user.name', 'Kit Fixture');
  git('config', 'user.email', 'fixture@example.invalid');
  git('config', 'commit.gpgsign', 'false');
  git('config', 'core.autocrlf', 'false');
  fs.writeFileSync(path.join(temporary, 'tracked.txt'), 'before\n');
  fs.writeFileSync(path.join(temporary, '.gitignore'), 'ignored.txt\n');
  git('add', '.');
  git('commit', '-qm', 'fixture baseline');
  assert.equal(hash(), run('code-tree.js', 'HEAD').stdout.trim(), 'clean hash matches committed tree');
  fs.writeFileSync(path.join(temporary, 'tracked.txt'), 'staged\n');
  git('add', 'tracked.txt');
  fs.writeFileSync(path.join(temporary, 'tracked.txt'), 'unstaged\n');
  const newFile = process.platform === 'win32' ? 'new yacht é.txt' : 'new " yacht é.txt';
  fs.writeFileSync(path.join(temporary, newFile), 'new\n');
  const expected = hash();
  fs.writeFileSync(path.join(temporary, 'ignored.txt'), 'ignored');
  fs.mkdirSync(path.join(temporary, 'docs/work/task'), { recursive: true });
  fs.writeFileSync(path.join(temporary, 'docs/work/task/report.md'), 'report');
  assert.equal(hash(), expected, 'ignored files and reports do not change hash');
  assert.equal(run('check-clean.js').status, 0, 'dirty tree is allowed');
  const baseline = 'docs/work/task/.review-baseline.json';
  assert.equal(run('check-clean.js', '--capture', baseline).status, 0);
  fs.writeFileSync(path.join(temporary, 'tracked.txt'), 'changed during review\n');
  assert.equal(run('check-clean.js', '--compare', baseline).status, 1, 'detects edits during review');
  fs.writeFileSync(path.join(temporary, 'tracked.txt'), 'unstaged\n');
  assert.equal(run('check-clean.js', '--compare', baseline).status, 0);
  git('add', 'tracked.txt', newFile);
  git('commit', '-qm', 'fixture changed files');
  assert.equal(hash(), expected, 'worktree hash matches eventual committed content');
  fs.renameSync(path.join(temporary, 'tracked.txt'), path.join(temporary, 'renamed.txt'));
  const renamed = hash();
  git('add', '-A');
  git('commit', '-qm', 'fixture rename');
  assert.equal(hash(), renamed, 'rename and deletion match committed tree');
  console.log('PASS: clean/dirty hashes, staged+unstaged, new quoted/unicode paths, ignored files, docs, baseline detection, rename/deletion.');
} finally {
  const parent = path.resolve(os.tmpdir()) + path.sep;
  if (!path.resolve(temporary).startsWith(parent) || !path.basename(temporary).startsWith('kit-working-tree-')) throw new Error('Unexpected cleanup target');
  fs.rmSync(temporary, { recursive: true, force: true });
}
