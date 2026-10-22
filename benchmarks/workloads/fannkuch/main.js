'use strict';
const n = parseInt(process.argv[2] || '10', 10);

function fannkuch(n) {
  const perm1 = Array.from({ length: n }, (_, i) => i);
  const count = new Array(n).fill(0);
  const perm = new Array(n).fill(0);
  let maxFlips = 0, checksum = 0, permCount = 0, r = n;
  for (;;) {
    while (r !== 1) { count[r - 1] = r; r--; }
    for (let i = 0; i < n; i++) perm[i] = perm1[i];
    let flips = 0, k;
    while ((k = perm[0]) !== 0) {
      let i = 0, j = k;
      while (i < j) { const t = perm[i]; perm[i] = perm[j]; perm[j] = t; i++; j--; }
      flips++;
    }
    if (flips > maxFlips) maxFlips = flips;
    checksum += permCount % 2 === 0 ? flips : -flips;
    for (;;) {
      if (r === n) return [checksum, maxFlips];
      const perm0 = perm1[0];
      for (let i = 0; i < r; i++) perm1[i] = perm1[i + 1];
      perm1[r] = perm0;
      count[r]--;
      if (count[r] > 0) break;
      r++;
    }
    permCount++;
  }
}

const [checksum, maxFlips] = fannkuch(n);
console.log(`${checksum}\nPfannkuchen(${n}) = ${maxFlips}`);
