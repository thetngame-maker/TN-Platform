<?php

declare(strict_types=1);

$roots = [
    dirname(__DIR__, 2) . '/contracts/php/src',
    dirname(__DIR__, 2) . '/entity/src',
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
use TN\Relationship\Relationship;
use TN\Relationship\RelationshipType;
use TN\Relationship\Repository\InMemoryRelationshipRepository;

$event = CanonicalEntity::create(EntityType::event(), ['title' => 'The Floozies']);
$venue = CanonicalEntity::create(EntityType::venue(), ['title' => 'The Caverns']);

$relationship = Relationship::create(
    RelationshipType::heldAt(),
    $event->id(),
    $venue->id(),
    ['source_external_id' => '14922'],
    0.95,
    'tixr',
);

assert(str_starts_with($relationship->id(), 'rel_'));
assert($relationship->type() === 'held_at');
assert($relationship->confidence() === 0.95);
assert($relationship->metadata()['source_provider'] === 'tixr');

$repository = new InMemoryRelationshipRepository();
$repository->save($relationship);

assert($repository->find($relationship->id()) === $relationship);
assert($repository->outgoing($event->id())->count() === 1);
assert($repository->incoming($venue->id(), RelationshipType::heldAt())->count() === 1);
assert($repository->outgoing($venue->id())->count() === 0);

fwrite(STDOUT, "PHP relationship engine passed\n");
