'use strict';
const n = parseInt(process.argv[2] || '300000', 10);
const rounds = parseInt(process.argv[3] || '10', 10);
let len = 0, total = 0;
for (let r = 0; r < rounds; r++) {
  const parts = [];
  for (let i = 0; i < n; i++) parts.push('item-' + i + '-' + (i * 7 % 1000));
  const s = parts.join(',').replaceAll('item', 'entry');
  len = s.length;
  total = 0;
  for (const p of s.split(',')) if (p.includes('-7')) total += p.toUpperCase().length;
}
console.log(`${len} ${total}`);
