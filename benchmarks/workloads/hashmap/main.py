import sys

n = int(sys.argv[1]) if len(sys.argv) > 1 else 2000000
m = n // 4
seed = 42
counts = {}
for i in range(n):
    seed = (seed * 48271) % 2147483647
    key = 'key' + str(seed % m)
    counts[key] = counts.get(key, 0) + 1
mx = 0
for c in counts.values():
    if c > mx:
        mx = c
hits = 0
for i in range(n):
    if ('key' + str(i)) in counts:
        hits += 1
print(len(counts), mx, hits)
