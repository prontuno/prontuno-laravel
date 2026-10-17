<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('Prontuno\Laravel')
    ->toUseStrictTypes();

arch('resource classes are final')
    ->expect([
        'Prontuno\\Laravel\\Resources\\Domains',
        'Prontuno\\Laravel\\Resources\\Emails',
        'Prontuno\\Laravel\\Resources\\SentEmailAttachments',
        'Prontuno\\Laravel\\Resources\\Suppressions',
        'Prontuno\\Laravel\\Resources\\Webhooks',
        'Prontuno\\Laravel\\Resources\\ApiKeys',
        'Prontuno\\Laravel\\Resources\\SmtpCredentials',
    ])
    ->toBeFinal();
