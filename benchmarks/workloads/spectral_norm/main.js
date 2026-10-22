'use strict';
const n = parseInt(process.argv[2] || '500', 10);

function a(i, j) {
  return 1.0 / ((((i + j) * (i + j + 1)) >> 1) + i + 1);
}

function mulAv(n, v) {
  const out = new Array(n);
  for (let i = 0; i < n; i++) {
    let s = 0.0;
    for (let j = 0; j < n; j++) s += a(i, j) * v[j];
    out[i] = s;
  }
  return out;
}

function mulAtv(n, v) {
  const out = new Array(n);
  for (let i = 0; i < n; i++) {
    let s = 0.0;
    for (let j = 0; j < n; j++) s += a(j, i) * v[j];
    out[i] = s;
  }
  return out;
}

const mulAtAv = (n, v) => mulAtv(n, mulAv(n, v));

let u = new Array(n).fill(1.0), v;
for (let i = 0; i < 10; i++) {
  v = mulAtAv(n, u);
  u = mulAtAv(n, v);
}
let vbv = 0, vv = 0;
for (let i = 0; i < n; i++) {
  vbv += u[i] * v[i];
  vv += v[i] * v[i];
}
console.log(Math.sqrt(vbv / vv).toFixed(9));
