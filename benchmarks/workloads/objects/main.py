import sys

n = int(sys.argv[1]) if len(sys.argv) > 1 else 3000000


class Rect:
    __slots__ = ('w', 'h')

    def __init__(self, w, h):
        self.w = w
        self.h = h

    def area(self):
        return self.w * self.h


class Square:
    __slots__ = ('s',)

    def __init__(self, s):
        self.s = s

    def area(self):
        return self.s * self.s


class Point:
    __slots__ = ('x', 'y')

    def __init__(self, x, y):
        self.x = x
        self.y = y

    def add(self, o):
        return Point(self.x + o.x, self.y + o.y)


p = Point(0, 0)
area = 0
for i in range(n):
    p = p.add(Point(i % 10, 1))
    shape = Rect(i % 7, 3) if i & 1 else Square(i % 5)
    area += shape.area()
print(p.x, p.y, area)
