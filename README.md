## installation

Add the custom repository to your composer.json:

```php
"repositories": [
    {
        "type": "vcs",
        "url": "git@github.com:snoke/doctrine-softdelete.git"
    }
],
```

checkout library `composer req snoke/doctrine-softdelete:dev-main`

## usage

your entity needs to use the HasLifecycleCallbacks-Annotation

```php
use Doctrine\ORM\Mapping as ORM;
use Snoke\SoftDelete\Trait\SoftDelete;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity]
class User
{
    use SoftDelete;
    ...
```

update database schema: `php bin/console make:migration` and `php bin/console do:mi:mi`

## example

The following example would not delete the entry from the database but will store the current timestamp in the deletedAt column:

```php
$user->delete();
$entityManager->flush();
var_dump($user->getDeletedAt());
```
```php
object(DateTimeImmutable)#674 (3) {
  ["date"]=>
  string(26) "2024-06-26 16:09:40.054742"
  ["timezone_type"]=>
  int(3)
  ["timezone"]=>
  string(13) "Europe/Berlin"
}

```

## Cascade
SoftDelete also respects hard delete cascade annotations!

In this example, soft deleting a user will result in hard deleting the orphans:
```php
use Snoke\SoftDelete\Trait\SoftDelete;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity]
class User
{
    use SoftDelete
    
    #[ORM\OneToMany(targetEntity: Orphan::class, mappedBy: 'user', cascade: ['persist','remove'])]
    private Collection $orphans;
```

Also you can use the Cascade-Interface to mark soft delete cascades
in this example, soft deleting user will result in soft deleting the orphans
```php
use Snoke\SoftDelete\Trait\SoftDelete;
use Snoke\SoftDelete\Annotation\SoftDeleteCascade;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity]
class User
{
    use SoftDelete;
    
    #[SoftDeleteCascade]
    #[ORM\OneToMany(targetEntity: Orphan::class, mappedBy: 'user', cascade: ['persist'])]
    private Collection $orphans;
```
## OrphanRemoval

to delete orphans **AND** remove the relation

```php
#[SoftDeleteCascade(orphanRemoval: true]
```

If you want to remove the relation to the deleted parent without deleting the children, you can still use the old Doctrine annotation:

```php
    #[ORM\OneToMany(orphanRemoval: true, targetEntity: Orphan::class, mappedBy: 'user', cascade: ['persist'])]
```