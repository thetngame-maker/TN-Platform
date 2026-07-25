<?php

declare(strict_types=1);

$roots = [
    dirname(__DIR__, 2) . '/contracts/php/src',
    dirname(__DIR__) . '/src',
];

foreach ($roots as $root) {
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    foreach ($files as $file) {
        require_once $file;
    }
}

use TN\Entity\CanonicalEntity;
use TN\Entity\EntityType;
use TN\Entity\LifecycleState;
use TN\Entity\Repository\InMemoryEntityRepository;
use TN\Entity\SourceReference;

$entity = CanonicalEntity::create(EntityType::event(), ['title' => 'The Floozies']);
assert(str_starts_with($entity->id()->value(), 'ent_'));
assert($entity->version()->number() === 1);
assert($entity->lifecycleState()->value() === 'draft');

$entity = $entity
    ->withSource(new SourceReference('Tixr', 'show-14922', 'https://example.com/show-14922'))
    ->transitionTo(LifecycleState::discovered());

assert($entity->version()->number() === 3);
assert($entity->lifecycleState()->value() === 'discovered');

$repository = new InMemoryEntityRepository();
$repository->save($entity);
assert($repository->find($entity->id()) === $entity);
assert($repository->findBySource('tixr', 'show-14922') === $entity);
assert(count(iterator_to_array($repository->snapshots($entity->id()))) === 1);

$updated = $entity->withAttributes(['title' => 'The Floozies Live']);
$repository->save($updated);
assert($repository->find($entity->id())?->attributes()['title'] === 'The Floozies Live');
assert(count(iterator_to_array($repository->snapshots($entity->id()))) === 2);

fwrite(STDOUT, "PHP entity foundation passed\n");
