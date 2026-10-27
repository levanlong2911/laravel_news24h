// Read-only working tree hash in the committed Code-Tree format; no index/object writes.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { ROOT, git } = require('./approval-lib');

function quotePath(name) {
  const bytes = Buffer.from(name);
  if (![...bytes].some(b => b < 32 || b >= 127 || b === 34 || b === 92)) return name;
  const escapes = { 7: '\\a', 8: '\\b', 9: '\\t', 10: '\\n', 11: '\\v', 12: '\\f', 13: '\\r', 34: '\\"', 92: '\\\\' };
  return '"' + [...bytes].map(b => escapes[b] || (b < 32 || b >= 127 ? '\\' + b.toString(8).padStart(3, '0') : String.fromCharCode(b))).join('') + '"';
}

function workingTreeHash() {
  const entries = new Map();
  for (const row of git(['ls-tree', '-rz', '--full-tree', 'HEAD']).split('\0').filter(Boolean)) {
    const tab = row.indexOf('\t');
    entries.set(row.slice(tab + 1), row.slice(0, tab));
  }
  const changed = new Set([
    ...git(['diff', '--no-ext-diff', '--name-only', '--no-renames', '-z', 'HEAD']).split('\0'),
    ...git(['ls-files', '--others', '--exclude-standard', '-z']).split('\0'),
  ].filter(Boolean));
  const modes = new Map(git(['ls-files', '--stage', '-z']).split('\0').filter(Boolean).map(row => {
    const tab = row.indexOf('\t');
    if (!row.slice(0, tab).endsWith(' 0')) throw new Error('Resolve merge conflicts before taking a snapshot.');
    return [row.slice(tab + 1), row.slice(0, 6)];
  }));
  for (const name of changed) {
    if (name.startsWith('docs/work/')) continue;
    if (/^\.env(?:\.|$)/.test(name)) throw new Error('Environment file changed; cannot hash secrets: ' + name);
    const absolute = path.join(ROOT, name);
    let stat;
    try { stat = fs.lstatSync(absolute); } catch (error) {
      if (error.code !== 'ENOENT') throw error;
      entries.delete(name);
      continue;
    }
    if (stat.isDirectory()) throw new Error('Unsupported changed directory/submodule: ' + name);
    const mode = stat.isSymbolicLink() ? '120000' : modes.get(name) || (process.platform !== 'win32' && (stat.mode & 0o111) ? '100755' : '100644');
    const bytes = stat.isSymbolicLink() ? Buffer.from(fs.readlinkSync(absolute)) : fs.readFileSync(absolute);
    const args = stat.isSymbolicLink() ? ['hash-object', '--stdin'] : ['hash-object', '--path=' + name, '--stdin'];
    const hash = git(args, { input: bytes, stdio: ['pipe', 'pipe', 'pipe'] }).trim();
    entries.set(name, mode + ' blob ' + hash);
  }
  const lines = [...entries].filter(([name]) => !name.startsWith('docs/work/')).map(([name, value]) => value + '\t' + quotePath(name)).sort();
  return crypto.createHash('sha256').update(lines.join('\n'), 'utf8').digest('hex').slice(0, 16);
}

module.exports = { workingTreeHash };
