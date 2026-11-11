<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Woodex\Sandbox\NativePreviewTransport;

require_once dirname(__DIR__, 3).'/tools/sandbox/NativePreviewTransport.php';

it('keeps only exact preview self URLs relative including JSON escaped paths', function () {
    $origin = 'https://preview.example';
    expect(NativePreviewTransport::relative($origin.'/admin?tab=list#top', $origin))->toBe('/admin?tab=list#top')
        ->and(NativePreviewTransport::relative('https:\/\/preview.example\/admin', $origin))->toBe('\/admin');
    foreach (['https://preview.example.evil/admin', 'https://external.example/admin', '//external.example/admin', 'mailto:owner@example.test'] as $url) {
        expect(NativePreviewTransport::relative($url, $origin))->toBe($url);
    }
    expect(NativePreviewTransport::relative($origin.'/admin', 'invalid origin'))->toBe($origin.'/admin');
});

it('rewrites navigation and redirect headers without changing signed HTML snapshots', function () {
    $origin = 'https://preview.example';
    $snapshot = 'wire:snapshot="'.htmlspecialchars(json_encode(['data' => ['url' => $origin.'/admin'], 'checksum' => 'synthetic']), ENT_QUOTES).'"';
    $response = new Response('<div '.$snapshot.'><a href="'.$origin.'/admin">Open</a></div>', 200, ['Content-Type' => 'text/html', 'Content-Length' => '999']);
    NativePreviewTransport::relativeResponse($response, $origin);
    expect($response->getContent())->toContain($snapshot, 'href="/admin"')->and($response->headers->has('Content-Length'))->toBeFalse();
    $redirect = new RedirectResponse($origin.'/admin');
    NativePreviewTransport::relativeResponse($redirect, $origin);
    expect($redirect->getTargetUrl())->toBe('/admin')->and($redirect->headers->get('Location'))->toBe('/admin');
});

it('rewrites Livewire effects but preserves signed JSON snapshots and non-Livewire JSON', function () {
    $origin = 'https://preview.example';
    $snapshot = json_encode(['data' => ['url' => $origin.'/admin'], 'checksum' => 'synthetic']);
    $response = new Response(json_encode(['components' => [['snapshot' => $snapshot, 'effects' => ['redirect' => $origin.'/admin', 'html' => '<a href="'.$origin.'/admin">Open</a>']]]]), 200, ['Content-Type' => 'application/json']);
    NativePreviewTransport::relativeResponse($response, $origin);
    $payload = json_decode($response->getContent(), true);
    expect($payload['components'][0]['snapshot'])->toBe($snapshot)
        ->and($payload['components'][0]['effects']['redirect'])->toBe('/admin')
        ->and($payload['components'][0]['effects']['html'])->toBe('<a href="/admin">Open</a>');
    $body = json_encode(['customer_url' => $origin.'/admin']);
    $plain = new Response($body, 200, ['Content-Type' => 'application/json']);
    NativePreviewTransport::relativeResponse($plain, $origin);
    expect($plain->getContent())->toBe($body);
    $csv = new Response($origin.'/admin', 200, ['Content-Type' => 'text/csv']);
    NativePreviewTransport::relativeResponse($csv, $origin);
    expect($csv->getContent())->toBe($origin.'/admin');
});
