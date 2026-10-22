import sys

n = int(sys.argv[1]) if len(sys.argv) > 1 else 1000
count = 0
for y in range(n):
    ci = 2.0 * y / n - 1.0
    for x in range(n):
        cr = 2.0 * x / n - 1.5
        zr = zi = 0.0
        inside = 1
        for _ in range(50):
            tr = zr * zr - zi * zi + cr
            zi = 2.0 * zr * zi + ci
            zr = tr
            if zr * zr + zi * zi > 4.0:
                inside = 0
                break
        count += inside
print(count)
