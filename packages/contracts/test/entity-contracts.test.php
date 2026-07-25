<?php

declare(strict_types=1);

$root = dirname(__DIR__) . '/php/src';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        require_once $file->getPathname();
    }
}

$required = [
    TN\Contracts\Entity\EntityIdInterface::class => ['value', 'equals', '__toString'],
    TN\Contracts\Entity\EntityTypeInterface::class => ['value', 'equals', '__toString'],
    TN\Contracts\Entity\EntityVersionInterface::class => ['number', 'isNewerThan', '__toString'],
    TN\Contracts\Entity\EntityLifecycleStateInterface::class => ['value', 'canTransitionTo', '__toString'],
    TN\Contracts\Entity\EntityInterface::class => ['id', 'type', 'version', 'lifecycleState', 'attributes', 'sourceReferences', 'relationships', 'updatedAt'],
    TN\Contracts\Entity\EntitySnapshotInterface::class => ['entityId', 'version', 'payload', 'recordedAt'],
    TN\Contracts\Repository\EntityRepositoryInterface::class => ['find', 'findBySource', 'save', 'delete', 'snapshots'],
    TN\Contracts\Source\SourceReferenceInterface::class => ['provider', 'externalId', 'url', 'checksum', 'importedAt'],
    TN\Contracts\Relationship\RelationshipInterface::class => ['id', 'type', 'sourceEntityId', 'targetEntityId', 'metadata', 'createdAt'],
];

foreach ($required as $interface => $methods) {
    if (!interface_exists($interface)) {
        fwrite(STDERR, "Missing interface: {$interface}\n");
        exit(1);
    }

    $reflection = new ReflectionClass($interface);
    foreach ($methods as $method) {
        if (!$reflection->hasMethod($method)) {
            fwrite(STDERR, "Missing method {$interface}::{$method}\n");
            exit(1);
        }
    }
}

fwrite(STDOUT, "PHP entity contracts passed\n");
