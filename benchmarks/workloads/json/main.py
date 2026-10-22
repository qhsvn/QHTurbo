import json
import sys

n = int(sys.argv[1]) if len(sys.argv) > 1 else 20000
rounds = int(sys.argv[2]) if len(sys.argv) > 2 else 20
records = [{
    'id': i,
    'name': 'user%d' % i,
    'email': 'user%d@example.com' % i,
    'tags': ['alpha', 'beta', 'gamma'],
    'score': i % 100,
    'active': i % 2 == 0,
} for i in range(n)]
length = total = 0
for _ in range(rounds):
    s = json.dumps(records, separators=(',', ':'))
    length = len(s)
    total = 0
    for rec in json.loads(s):
        if rec['active']:
            total += rec['score']
print(length, total)
