<?php

declare(strict_types=1);

$roots = [
    dirname(__DIR__, 2) . '/contracts/php/src',
    dirname(__DIR__, 2) . '/entity/src',
    dirname(__DIR__, 2) . '/relationship/src',
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

use TN\Entity\EntityId;
use TN\Graph\Graph;
use TN\Relationship\Relationship;
use TN\Relationship\RelationshipType;
use TN\Relationship\Repository\InMemoryRelationshipRepository;

$event = EntityId::generate();
$venue = EntityId::generate();
$town = EntityId::generate();
$county = EntityId::generate();

$repository = new InMemoryRelationshipRepository();
$repository->save(Relationship::create(RelationshipType::heldAt(), $event, $venue));
$repository->save(Relationship::create(RelationshipType::locatedIn(), $venue, $town));
$repository->save(Relationship::create(RelationshipType::fromString('part_of'), $town, $county));

$graph = new Graph($repository);
$paths = $graph->traverse($event, 3);

assert(count($paths) === 3);
assert($paths[0]->depth() === 1);
assert($paths[2]->depth() === 3);
assert($paths[2]->end()->equals($county));
assert($graph->connected($event, $county, 3));
assert(!$graph->connected($event, $county, 2));

$heldAt = $graph->traverse($event, 3, RelationshipType::heldAt());
assert(count($heldAt) === 1);
assert($heldAt[0]->end()->equals($venue));

fwrite(STDOUT, "PHP graph engine passed\n");
