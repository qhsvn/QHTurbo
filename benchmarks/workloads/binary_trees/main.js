'use strict';
const maxDepth = Math.max(6, parseInt(process.argv[2] || '16', 10));

function bottomUpTree(depth) {
  return depth > 0 ? [bottomUpTree(depth - 1), bottomUpTree(depth - 1)] : [null, null];
}

function itemCheck(t) {
  return t[0] === null ? 1 : 1 + itemCheck(t[0]) + itemCheck(t[1]);
}

const stretch = maxDepth + 1;
console.log(`stretch tree of depth ${stretch}\t check: ${itemCheck(bottomUpTree(stretch))}`);
const longLived = bottomUpTree(maxDepth);
for (let d = 4; d <= maxDepth; d += 2) {
  const iterations = 1 << (maxDepth - d + 4);
  let check = 0;
  for (let i = 0; i < iterations; i++) check += itemCheck(bottomUpTree(d));
  console.log(`${iterations}\t trees of depth ${d}\t check: ${check}`);
}
console.log(`long lived tree of depth ${maxDepth}\t check: ${itemCheck(longLived)}`);
