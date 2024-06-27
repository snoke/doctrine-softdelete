<?php

namespace Snoke\DoctrineSoftDelete;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\PersistentCollection;
use ReflectionClass;
use ReflectionProperty;

trait SoftDeleteTrait
{
    private array $processedObjects = [];

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    private function recursiveSoftDelete(EntityManagerInterface $em, object $entity): void
    {
        $objectId = spl_object_id($entity);
        if (in_array($objectId, $this->processedObjects, true)) {
            return;
        }

        if (!in_array(DeletedAt::class, class_uses($entity), true)) {
            return;
        }

        $this->processedObjects[] = $objectId;
        $entity->delete();
        $em->persist($entity);

        $reflectionClass = new ReflectionClass($entity);
        $properties = $reflectionClass->getProperties();
        foreach ($properties as $property) {
            $property->setAccessible(true);
            $propertyValue = $property->getValue($entity);
            if ($propertyValue === null) {
                continue;
            }

            if ($this->hasAttribute($property, CascadeSoftDelete::class)) {
                $this->processPropertyValue($em, $propertyValue, true);
            } else {
                $this->processHardDeleteAttributes($em, $property, $propertyValue);
            }
        }
    }

    private function hasAttribute(ReflectionProperty $property, string $attributeClass): bool
    {
        return count($property->getAttributes($attributeClass)) > 0;
    }

    private function processHardDeleteAttributes(EntityManagerInterface $em, ReflectionProperty $property, $propertyValue): void
    {
        $attributes = $property->getAttributes();
        foreach ($attributes as $attribute) {
            $instance = $attribute->newInstance();
            if ($this->isHardDelete($instance)) {
                $this->processPropertyValue($em, $propertyValue, false);
                break;
            }
        }
    }

    private function isHardDelete(object $attributeInstance): bool
    {
        if (isset($attributeInstance->cascade) && in_array('remove', $attributeInstance->cascade, true)) {
            return true;
        }

        if (isset($attributeInstance->orphanRemoval) && $attributeInstance->orphanRemoval === true) {
            return true;
        }

        return false;
    }

    private function processPropertyValue(EntityManagerInterface $em, $propertyValue, bool $isSoftDelete): void
    {
        if ($propertyValue instanceof PersistentCollection || is_array($propertyValue)) {
            foreach ($propertyValue as $element) {
                $isSoftDelete ? $this->recursiveSoftDelete($em, $element) : $em->remove($element);
            }
        } elseif (is_object($propertyValue)) {
            $isSoftDelete ? $this->recursiveSoftDelete($em, $propertyValue) : $em->remove($propertyValue);
        }
    }

    private function setDeletedAt(?DateTimeImmutable $deletedAt = null): void
    {
        $this->deletedAt = $deletedAt ?: new DateTimeImmutable();
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function delete(): void
    {
        $this->setDeletedAt();
    }

    #[ORM\PreFlush]
    public function deletedAtPreFlush(PreFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        if (null !== $this->deletedAt) {
            $this->recursiveSoftDelete($em, $this);
        }
    }
}
