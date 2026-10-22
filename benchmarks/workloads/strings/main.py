import sys

n = int(sys.argv[1]) if len(sys.argv) > 1 else 300000
rounds = int(sys.argv[2]) if len(sys.argv) > 2 else 10
length = total = 0
for _ in range(rounds):
    parts = []
    for i in range(n):
        parts.append('item-' + str(i) + '-' + str(i * 7 % 1000))
    s = ','.join(parts).replace('item', 'entry')
    length = len(s)
    total = 0
    for p in s.split(','):
        if '-7' in p:
            total += len(p.upper())
print(length, total)
