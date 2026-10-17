<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Prontuno\Laravel\Mail\ProntunoTransport;
use Prontuno\Laravel\Prontuno;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\RawMessage;

beforeEach(function () {
    config()->set('prontuno.api_key', 'pno_sk_test');
    config()->set('prontuno.base_url', 'https://api.prontuno.test/v1');
    config()->set('mail.default', 'prontuno');
    config()->set('mail.mailers.prontuno', ['transport' => 'prontuno']);
});

it('registers the prontuno mail transport', function () {
    expect(Mail::mailer('prontuno')->getSymfonyTransport())
        ->toBeInstanceOf(ProntunoTransport::class)
        ->and((string) Mail::mailer('prontuno')->getSymfonyTransport())->toBe('prontuno');
});

it('sends laravel mail through the prontuno api', function () {
    Http::fake([
        'api.prontuno.test/v1/emails' => Http::response(['emails' => [
            ['id' => 'email_1', 'to' => 'ada@example.com', 'status' => 'queued'],
            ['id' => 'email_2', 'to' => 'grace@example.com', 'status' => 'queued'],
        ]], 202),
    ]);

    $sent = Mail::raw('Plain body', function (Message $message): void {
        $message->from('hello@example.com', 'Acme')
            ->to('ada@example.com', 'Ada')
            ->cc('grace@example.com')
            ->replyTo('support@example.com')
            ->subject('Welcome')
            ->attachData('report contents', 'report.txt', ['mime' => 'text/plain']);
        $message->getSymfonyMessage()->getHeaders()->addTextHeader('X-Entity-Ref-ID', 'order-1');
    });

    expect($sent?->getMessageId())->toBe('email_1')
        ->and($sent?->getOriginalMessage()->getHeaders()->get(ProntunoTransport::EMAIL_ID_HEADER)?->getBodyAsString())
        ->toBe('email_1,email_2');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.prontuno.test/v1/emails'
            && $request->hasHeader('Authorization', 'Bearer pno_sk_test')
            && $data['from'] === '"Acme" <hello@example.com>'
            && $data['to'] === ['"Ada" <ada@example.com>']
            && $data['cc'] === ['grace@example.com']
            && ! isset($data['bcc'])
            && $data['reply_to'] === 'support@example.com'
            && $data['subject'] === 'Welcome'
            && $data['text'] === 'Plain body'
            && ! isset($data['html'])
            && $data['headers'] === ['X-Entity-Ref-ID' => 'order-1']
            && $data['attachments'] === [[
                'filename' => 'report.txt',
                'content' => base64_encode('report contents'),
                'encoding' => 'base64',
                'content_type' => 'text/plain',
            ]];
    });
});

it('uses a mailer specific api key when configured', function () {
    config()->set('mail.mailers.prontuno', ['transport' => 'prontuno', 'key' => 'pno_sk_other']);
    Http::fake(['api.prontuno.test/v1/emails' => Http::response(['emails' => [['id' => 'email_1']]], 202)]);

    Mail::raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('Hi'));

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer pno_sk_other'));
});

it('wraps api failures in a transport exception', function () {
    Http::fake(['api.prontuno.test/v1/emails' => Http::response([
        'error' => ['code' => 'insufficient_credits', 'message' => 'Top up your credits', 'retryable' => false],
    ], 402)]);

    Mail::raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('Hi'));
})->throws(TransportException::class, 'Top up your credits');

it('maps bcc, html, stream bodies, and inline or unnamed attachments', function () {
    Http::fake(['api.prontuno.test/v1/emails' => Http::response(['emails' => []], 202)]);

    $text = fopen('php://memory', 'r+');
    fwrite($text, 'Streamed text');
    rewind($text);

    $email = (new Email)
        ->from(new Address('hello@example.com', 'Acme'))
        ->to('ada@example.com')
        ->bcc('audit@example.com', 'archive@example.com')
        ->replyTo('first@example.com', 'second@example.com')
        ->subject('Inline images')
        ->text($text)
        ->html('<img src="cid:logo">')
        ->addPart((new DataPart('logo-bytes', 'logo.png', 'image/png'))->asInline())
        ->addPart((new DataPart('banner-bytes', 'banner.png', 'image/png'))->asInline()->setContentId('banner@acme'))
        ->addPart(new DataPart('raw-bytes'));

    $sent = (new ProntunoTransport(app(Prontuno::class)))->send($email);

    expect($sent?->getOriginalMessage()->getHeaders()->has(ProntunoTransport::EMAIL_ID_HEADER))->toBeFalse();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['to'] === ['ada@example.com']
            && $data['bcc'] === ['audit@example.com', 'archive@example.com']
            && ! isset($data['cc'])
            && $data['reply_to'] === 'first@example.com'
            && $data['text'] === 'Streamed text'
            && $data['html'] === '<img src="cid:logo">'
            && $data['attachments'][0]['content_id'] === 'logo.png'
            && $data['attachments'][0]['content'] === base64_encode('logo-bytes')
            && $data['attachments'][1]['content_id'] === 'banner@acme'
            && $data['attachments'][2]['filename'] === 'attachment'
            && $data['attachments'][2]['content_type'] === 'application/octet-stream'
            && ! isset($data['attachments'][2]['content_id']);
    });
});

it('rejects raw messages that are not MIME emails', function () {
    $envelope = new Envelope(new Address('a@example.com'), [new Address('b@example.com')]);

    (new ProntunoTransport(app(Prontuno::class)))->send(new RawMessage('raw'), $envelope);
})->throws(TransportException::class, 'can only send MIME messages');

it('wraps connection failures in a transport exception', function () {
    Http::fake(['api.prontuno.test/v1/emails' => fn ($request) => Create::rejectionFor(
        new ConnectException('Connection refused', $request->toPsrRequest()),
    )]);

    Mail::raw('Hi', fn (Message $message) => $message->from('a@example.com')->to('b@example.com')->subject('Hi'));
})->throws(TransportException::class, 'Connection refused');

it('limits cc and bcc to an explicit envelope', function () {
    Http::fake(['api.prontuno.test/v1/emails' => Http::response(['emails' => [['id' => 'email_1']]], 202)]);

    $email = (new Email)
        ->from('hello@example.com')
        ->to(new Address('ada@example.com', 'Ada'))
        ->cc('grace@example.com')
        ->bcc('audit@example.com')
        ->subject('Quarterly report')
        ->text('Confidential')
        ->attach('secret', 'report.txt', 'text/plain');

    // Redirect everything to a review mailbox, as a staging or approval flow would.
    $envelope = new Envelope(new Address('hello@example.com'), [new Address('review@example.com')]);

    (new ProntunoTransport(app(Prontuno::class)))->send($email, $envelope);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $data['to'] === ['review@example.com']
            && ! isset($data['cc'])
            && ! isset($data['bcc'])
            && count($data['attachments']) === 1;
    });
});

it('keeps envelope recipients that are cc or bcc in their header role', function () {
    Http::fake(['api.prontuno.test/v1/emails' => Http::response(['emails' => [['id' => 'email_1']]], 202)]);

    $email = (new Email)
        ->from('hello@example.com')
        ->to(new Address('ada@example.com', 'Ada'))
        ->cc(new Address('Grace@Example.com', 'Grace'), 'excluded-cc@example.com')
        ->bcc('audit@example.com', 'excluded-bcc@example.com')
        ->subject('Hi')
        ->text('Hi');

    // Bare, differently cased addresses: matching is by mailbox, so Grace isn't
    // also sent as To, and display names come from the message headers.
    $envelope = new Envelope(new Address('hello@example.com'), [
        new Address('ada@example.com'),
        new Address('grace@example.com'),
        new Address('AUDIT@example.com'),
        new Address('ada@example.com'),
    ]);

    (new ProntunoTransport(app(Prontuno::class)))->send($email, $envelope);

    Http::assertSent(fn (Request $request): bool => $request->data()['to'] === ['"Ada" <ada@example.com>']
        && $request->data()['cc'] === ['"Grace" <Grace@Example.com>']
        && $request->data()['bcc'] === ['audit@example.com']);
});

it('does not send an address in both cc and bcc', function () {
    Http::fake(['api.prontuno.test/v1/emails' => Http::response(['emails' => [['id' => 'email_1']]], 202)]);

    $email = (new Email)
        ->from('hello@example.com')
        ->to('ada@example.com')
        ->cc('grace@example.com')
        ->bcc('grace@example.com')
        ->subject('Hi')
        ->text('Hi');

    (new ProntunoTransport(app(Prontuno::class)))->send($email);

    Http::assertSent(fn (Request $request): bool => $request->data()['to'] === ['ada@example.com']
        && $request->data()['cc'] === ['grace@example.com']
        && ! isset($request->data()['bcc']));
});

it('uses the message id as the idempotency key and retries server errors', function () {
    Http::fake([
        'api.prontuno.test/v1/emails' => Http::sequence()
            ->push(['error' => ['code' => 'unavailable', 'message' => 'Down', 'retryable' => true]], 503, ['Retry-After' => '0'])
            ->push(['emails' => [['id' => 'email_1', 'to' => 'ada@example.com', 'status' => 'queued']]], 202),
    ]);

    $email = (new Email)->from('hello@example.com')->to('ada@example.com')->subject('Hi')->text('Hi');
    $email->getHeaders()->addIdHeader('Message-ID', 'order-1@example.com');

    $sent = Mail::mailer('prontuno')->getSymfonyTransport()->send($email);

    expect($sent?->getMessageId())->toBe('email_1');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', 'order-1@example.com'));
});
