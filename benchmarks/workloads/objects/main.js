'use strict';
const n = parseInt(process.argv[2] || '3000000', 10);

class Rect {
  constructor(w, h) { this.w = w; this.h = h; }
  area() { return this.w * this.h; }
}

class Square {
  constructor(s) { this.s = s; }
  area() { return this.s * this.s; }
}

class Point {
  constructor(x, y) { this.x = x; this.y = y; }
  add(o) { return new Point(this.x + o.x, this.y + o.y); }
}

let p = new Point(0, 0);
let area = 0;
for (let i = 0; i < n; i++) {
  p = p.add(new Point(i % 10, 1));
  const shape = (i & 1) ? new Rect(i % 7, 3) : new Square(i % 5);
  area += shape.area();
}
console.log(`${p.x} ${p.y} ${area}`);
