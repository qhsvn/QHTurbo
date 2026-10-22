<?php

// Test controller for runtime/tests/frameworks.php (copied into src/Controller
// of a throw-away Symfony app).

namespace App\Controller;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class QhtController
{
    public function __construct(private QhtCounter $counter)
    {
    }

    #[Route('/qht/hello')]
    public function hello(): Response
    {
        return new Response('hello');
    }

    #[Route('/qht/pid')]
    public function pid(): Response
    {
        return new Response((string) getmypid());
    }

    #[Route('/qht/counter')]
    public function counter(): Response
    {
        return new Response((string) ++$this->counter->n);
    }

    #[Route('/qht/json')]
    public function json(): JsonResponse
    {
        return new JsonResponse(['framework' => 'symfony', 'items' => range(1, 10)]);
    }

    #[Route('/qht/upload', methods: ['POST'])]
    public function upload(Request $r): JsonResponse
    {
        $f = $r->files->get('doc');
        return new JsonResponse(['title' => $r->request->get('title'), 'name' => $f?->getClientOriginalName(),
            'size' => $f?->getSize(), 'valid' => $f?->isValid()]);
    }

    #[Route('/qht/cookie')]
    public function cookie(): Response
    {
        $r = new Response('cookies');
        $r->headers->setCookie(Cookie::create('a', '1'));
        $r->headers->setCookie(Cookie::create('b', '2'));
        return $r;
    }

    #[Route('/qht/stream')]
    public function stream(): StreamedResponse
    {
        // ob_flush()/flush() must not send the chunks to the worker's STDOUT.
        return new StreamedResponse(static function (): void {
            foreach (['a', 'b', 'c'] as $chunk) {
                echo $chunk;
                ob_flush();
                flush();
            }
        });
    }

    #[Route('/qht/boom')]
    public function boom(): Response
    {
        throw new \RuntimeException('secret detail');
    }
}
