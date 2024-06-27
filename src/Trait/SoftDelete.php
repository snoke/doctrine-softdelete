<?php

namespace Snoke\DoctrineSoftDelete\Trait;

use Snoke\DoctrineSoftDelete\Annotation\Cascade;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DateTimeImmutable;

trait SoftDelete
{
    private array $processedObjects = [];

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    private function setDeletedAt(?DateTimeImmutable $deletedAt): static
    {
        $this->deletedAt = $deletedAt ?: $this->setDeletedAt(new DateTimeImmutable());

        return $this;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function delete() {
        $this->setDeletedAt(new DateTimeImmutable());
    }

    #[ORM\PreFlush]
    public function softDeletePreFlush(PreFlushEventArgs $args)
    {
        $em = $args->getObjectManager();

        if (null !== $this->deletedAt) {
            $this->deleteCascade($em, $this);
        }
    }

    private function deleteCascade(EntityManagerInterface $em, object $entity): void
    {
        // Prevent recursion loops
        $objectId = spl_object_id($entity);
        if (in_array($objectId, $this->processedObjects, true)) {
            return;
        }

        // Check if the entity uses the SoftDelete trait
        if (!in_array(SoftDelete::class, class_uses($entity), true)) {
            return;
        }

        $this->processedObjects[] = $objectId;
        $entity->delete();
        $em->persist($entity);

        $reflectionClass = new \ReflectionClass($entity);
        $properties = $reflectionClass->getProperties();
        foreach ($properties as $property) {
            $property->setAccessible(true);
            $propertyValue = $property->getValue($entity);
            if ($propertyValue === null) {
                continue;
            }

            // handle soft delete cascade
            if ($this->hasAttribute($property, Cascade::class)) {
                $this->processPropertyValue($em, $propertyValue, true);
            } else {
                // handle hard delete cascade
                $attributes = $property->getAttributes();
                foreach ($attributes as $attribute) {
                    $instance = $attribute->newInstance();
                    if ($this->isHardDelete($instance)) {
                        $this->processPropertyValue($em, $propertyValue, false);
                        break;
                    }
                }
            }
        }
    }
}