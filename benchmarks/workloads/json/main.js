'use strict';
const n = parseInt(process.argv[2] || '20000', 10);
const rounds = parseInt(process.argv[3] || '20', 10);
const records = [];
for (let i = 0; i < n; i++) {
  records.push({
    id: i,
    name: `user${i}`,
    email: `user${i}@example.com`,
    tags: ['alpha', 'beta', 'gamma'],
    score: i % 100,
    active: i % 2 === 0,
  });
}
let len = 0, sum = 0;
for (let r = 0; r < rounds; r++) {
  const json = JSON.stringify(records);
  len = json.length;
  sum = 0;
  for (const rec of JSON.parse(json)) if (rec.active) sum += rec.score;
}
console.log(`${len} ${sum}`);
