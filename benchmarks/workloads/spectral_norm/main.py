import sys
from math import sqrt

n = int(sys.argv[1]) if len(sys.argv) > 1 else 500


def a(i, j):
    return 1.0 / ((((i + j) * (i + j + 1)) >> 1) + i + 1)


def mul_av(n, v):
    out = [0.0] * n
    for i in range(n):
        s = 0.0
        for j in range(n):
            s += a(i, j) * v[j]
        out[i] = s
    return out


def mul_atv(n, v):
    out = [0.0] * n
    for i in range(n):
        s = 0.0
        for j in range(n):
            s += a(j, i) * v[j]
        out[i] = s
    return out


def mul_atav(n, v):
    return mul_atv(n, mul_av(n, v))


u = [1.0] * n
for _ in range(10):
    v = mul_atav(n, u)
    u = mul_atav(n, v)
vbv = vv = 0.0
for i in range(n):
    vbv += u[i] * v[i]
    vv += v[i] * v[i]
print("%.9f" % sqrt(vbv / vv))
