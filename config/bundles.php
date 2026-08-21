<?php

declare(strict_types=1);

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    Nelmio\ApiDocBundle\NelmioApiDocBundle::class => ['all' => true],
    // The one place this service produces markup: the pages that draw a form.
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    // Behaviour for the pages, delivered the way Symfony delivers it: Stimulus
    // controllers over AssetMapper, no build step and no package manager.
    Symfony\UX\StimulusBundle\StimulusBundle::class => ['all' => true],
    // Icons as SVG in the markup, imported into the repository rather than
    // fetched at runtime.
    Symfony\UX\Icons\UXIconsBundle::class => ['all' => true],
    // Where a form's bytes live: a directory here, a bucket somewhere else. Which
    // one is configuration, and only one adapter class of ours knows it exists.
    League\FlysystemBundle\FlysystemBundle::class => ['all' => true],
    DAMA\DoctrineTestBundle\DAMADoctrineTestBundle::class => ['test' => true],
];
