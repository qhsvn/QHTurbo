'use strict';
const n = parseInt(process.argv[2] || '2000000', 10);
const m = Math.floor(n / 4);
let seed = 42;
const counts = new Map();
for (let i = 0; i < n; i++) {
  seed = (seed * 48271) % 2147483647;
  const key = 'key' + (seed % m);
  counts.set(key, (counts.get(key) || 0) + 1);
}
let max = 0;
for (const c of counts.values()) if (c > max) max = c;
let hits = 0;
for (let i = 0; i < n; i++) if (counts.has('key' + i)) hits++;
console.log(`${counts.size} ${max} ${hits}`);
