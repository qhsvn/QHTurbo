'use strict';
const n = parseInt(process.argv[2] || '1000', 10);
let count = 0;
for (let y = 0; y < n; y++) {
  const ci = 2.0 * y / n - 1.0;
  for (let x = 0; x < n; x++) {
    const cr = 2.0 * x / n - 1.5;
    let zr = 0.0, zi = 0.0, inside = 1;
    for (let i = 0; i < 50; i++) {
      const tr = zr * zr - zi * zi + cr;
      zi = 2.0 * zr * zi + ci;
      zr = tr;
      if (zr * zr + zi * zi > 4.0) { inside = 0; break; }
    }
    count += inside;
  }
}
console.log(count);
