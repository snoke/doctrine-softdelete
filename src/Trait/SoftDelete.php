<?php

namespace Snoke\SoftDelete\Trait;

use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

trait SoftDelete
{
    /**
     * @var DateTimeImmutable|null
     */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    /**
     * @param DateTimeImmutable|null $deletedAt
     * @return void
     */
    private function setDeletedAt(?DateTimeImmutable $deletedAt = null): void
    {
        $this->deletedAt = $deletedAt ?: new DateTimeImmutable();
    }

    /**
     * @return DateTimeImmutable|null
     */
    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    /**
     * @return void
     */
    public function delete(): void
    {
        $this->setDeletedAt();
    }
}
