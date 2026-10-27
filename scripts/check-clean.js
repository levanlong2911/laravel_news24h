#!/usr/bin/env node
// Uncommitted code is allowed. Capture/compare detects changes during review.
const fs = require('fs');
const path = require('path');
const { ROOT, git } = require('./approval-lib');
const { workingTreeHash } = require('./working-tree');
try {
  const [option, file, ...extra] = process.argv.slice(2);
  if (option) {
    if (!['--capture', '--compare'].includes(option) || !file || extra.length) throw new Error('Usage: check-clean.js [--capture|--compare docs/work/<slug>/.review-baseline.json]');
    const absolute = path.resolve(ROOT, file);
    const work = path.resolve(ROOT, 'docs/work') + path.sep;
    if (!absolute.startsWith(work) || path.extname(absolute) !== '.json') throw new Error('Snapshot must be a JSON file inside docs/work/.');
    const hash = workingTreeHash();
    if (option === '--capture') {
      if (fs.existsSync(absolute)) throw new Error('Baseline exists; compare it before starting another review.');
      fs.mkdirSync(path.dirname(absolute), { recursive: true });
      fs.writeFileSync(absolute, JSON.stringify({ codeTree: hash }, null, 2) + '\n');
      console.log('Captured working tree: ' + hash);
    } else {
      if (JSON.parse(fs.readFileSync(absolute, 'utf8')).codeTree !== hash) throw new Error('Code changed outside docs/work/ during this step. Inspect the diff; do not reset pre-existing changes.');
      console.log('Working tree unchanged outside docs/work/.');
      fs.unlinkSync(absolute);
    }
  } else {
    const out = git(['status', '--porcelain', '--untracked-files=all']);
    console.log(out.trim() ? 'Uncommitted changes are allowed. Use --capture/--compare for a review baseline.' : 'Working tree is clean.');
  }
} catch (error) {
  console.error(error.message);
  process.exit(1);
}
