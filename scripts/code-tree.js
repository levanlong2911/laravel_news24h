#!/usr/bin/env node
// Default: working tree including uncommitted/untracked code, excluding docs/work/.
// Explicit ref: committed tree, in the same format used by CI.
const { codeTreeHash } = require('./approval-lib');
const { workingTreeHash } = require('./working-tree');
try {
  console.log(process.argv[2] ? codeTreeHash(process.argv[2]) : workingTreeHash());
} catch (error) {
  console.error(error.message);
  process.exit(1);
}
