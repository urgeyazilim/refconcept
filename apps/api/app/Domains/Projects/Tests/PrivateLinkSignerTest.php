<?php

declare(strict_types=1);

use App\Support\Storage\PrivateLinkSigner;

/**
 * A signed link has to be openable by the thing that was given it.
 *
 * Object storage is reached by two names: this container talks to MinIO on the Docker
 * network, a browser talks to it through a published port. A temporary URL carries the host
 * in its signature, so a link signed for the first is rejected at the second — and the
 * symptom is not an error anybody sees. It is a room photograph that renders as a broken
 * image with its filename showing, which reads to a customer as "my upload did not work".
 */
it('signs a link for the host the browser will use', function (): void {
    config()->set('refconcept.storage.public_endpoint', 'http://localhost:59000');

    $url = app(PrivateLinkSigner::class)->url(
        config('refconcept.storage.private_disk'),
        'probe/example.jpg',
        now()->addMinutes(5),
    );

    expect($url)->toStartWith('http://localhost:59000/')
        ->and($url)->not->toContain('minio:9000')
        // Still a signature, not a bare public path: the whole point of the private disk
        // is that a URL without one opens nothing.
        ->and($url)->toContain('X-Amz-Signature');
});

it('leaves the disk alone when the two names are the same', function (): void {
    // The production case. Nothing is rebuilt and nothing is rewritten.
    config()->set('refconcept.storage.public_endpoint', '');

    $url = app(PrivateLinkSigner::class)->url(
        config('refconcept.storage.private_disk'),
        'probe/example.jpg',
        now()->addMinutes(5),
    );

    expect($url)->toContain('X-Amz-Signature');
});

it('never hands back a link that opens without a signature', function (): void {
    /*
     * The guarantee that matters, and it has to hold on every driver rather than only on
     * S3. A caller receiving a permanent path instead of a signed link would be publishing
     * private files while appearing to work — room photographs, onboarding documents, bank
     * receipts.
     *
     * The local driver satisfies it differently: Laravel serves it through a signed route
     * with its own expiry. Different mechanism, same promise, which is why this asserts the
     * promise rather than the mechanism.
     */
    $url = app(PrivateLinkSigner::class)->url('local', 'probe/example.jpg', now()->addMinutes(5));

    expect($url)->toContain('signature')
        ->and($url)->toContain('expires');
});
